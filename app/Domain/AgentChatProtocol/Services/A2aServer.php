<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\AgentChatProtocol\Enums\MessageDirection;
use App\Domain\AgentChatProtocol\Enums\MessageStatus;
use App\Domain\AgentChatProtocol\Exceptions\A2aRpcException;
use App\Domain\AgentChatProtocol\Exceptions\InvalidProtocolMessageException;
use App\Domain\AgentChatProtocol\Models\AgentChatMessage;
use Illuminate\Support\Str;

/**
 * Server side of A2A: answers `message/send` and `tasks/get` for one of our own
 * agents, so an external A2A peer can hold a conversation with it.
 *
 * This is a protocol facade, NOT a second execution engine. `message/send`
 * funnels into the same ProtocolReceiver the REST chat endpoint uses, which
 * fires ChatMessageReceived and lets ExecuteAgentOnChatMessage run the agent on
 * the queue. Because that path is asynchronous, we always answer with an A2A
 * Task rather than an immediate Message — which is exactly what the Task
 * lifecycle exists for. `tasks/get` then reports progress by reading the
 * inbound/outbound AgentChatMessage pair:
 *
 *   outbound reply exists (in_reply_to = inbound msg_id) -> completed
 *   inbound marked Failed by the listener                -> failed
 *   otherwise                                            -> working
 *
 * @see https://a2a-protocol.org/latest/specification/
 */
class A2aServer
{
    public function __construct(private readonly ProtocolReceiver $receiver) {}

    /**
     * Execute one JSON-RPC call and return the complete response envelope.
     *
     * @param  array<string, mixed>  $rpc
     * @return array<string, mixed>
     */
    public function handle(Agent $agent, array $rpc): array
    {
        $id = $rpc['id'] ?? null;

        try {
            if (($rpc['jsonrpc'] ?? null) !== '2.0') {
                throw new A2aRpcException(A2aRpcException::INVALID_REQUEST, 'jsonrpc must be "2.0"');
            }

            $method = (string) ($rpc['method'] ?? '');
            $params = (array) ($rpc['params'] ?? []);

            $result = match ($method) {
                'message/send' => $this->messageSend($agent, $params),
                'tasks/get' => $this->tasksGet($agent, $params),
                // Cancellation would have to reach into a queued agent run, which
                // there is no mechanism for. The spec has an error for exactly
                // this, and returning it beats pretending the task stopped.
                'tasks/cancel' => throw new A2aRpcException(
                    A2aRpcException::TASK_NOT_CANCELABLE,
                    'This agent does not support cancelling an in-flight task.',
                ),
                default => throw A2aRpcException::methodNotFound($method),
            };

            return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
        } catch (A2aRpcException $e) {
            return $this->error($id, $e->rpcCode, $e->getMessage());
        } catch (InvalidProtocolMessageException $e) {
            return $this->error($id, A2aRpcException::INVALID_PARAMS, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function messageSend(Agent $agent, array $params): array
    {
        $message = (array) ($params['message'] ?? []);
        $text = $this->textFromParts((array) ($message['parts'] ?? []));

        if ($text === '') {
            throw A2aRpcException::invalidParams('message.parts must contain at least one non-empty text part.');
        }

        // The protocol validator requires UUIDs, while A2A lets a peer use any
        // string for messageId/contextId. Reuse theirs when it happens to be a
        // UUID, otherwise mint one and keep the original for correlation.
        $peerMessageId = (string) ($message['messageId'] ?? '');
        $peerContextId = (string) ($message['contextId'] ?? $params['contextId'] ?? '');

        $msgId = Str::isUuid($peerMessageId) ? $peerMessageId : Str::uuid7()->toString();
        $sessionId = Str::isUuid($peerContextId) ? $peerContextId : Str::uuid7()->toString();

        $inbound = $this->receiver->receiveChat($agent, [
            'msg_id' => $msgId,
            'session_id' => $sessionId,
            'from' => 'a2a:peer',
            'to' => (string) ($agent->chat_protocol_slug ?? $agent->id),
            'content' => $text,
            'timestamp' => now()->toIso8601String(),
            'metadata' => array_filter([
                'a2a' => true,
                'peer_message_id' => $peerMessageId !== '' ? $peerMessageId : null,
                'peer_context_id' => $peerContextId !== '' ? $peerContextId : null,
            ], fn ($v) => $v !== null),
        ]);

        return $this->task($inbound, 'submitted');
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function tasksGet(Agent $agent, array $params): array
    {
        $id = (string) ($params['id'] ?? '');
        if ($id === '') {
            throw A2aRpcException::invalidParams('tasks/get requires an "id".');
        }

        // Scope by agent as well as id: a task id from one agent must never
        // resolve against another, even within the same team.
        $inbound = AgentChatMessage::withoutGlobalScopes()
            ->where('agent_id', $agent->id)
            ->where('direction', MessageDirection::Inbound)
            ->where('msg_id', $id)
            ->first();

        if ($inbound === null) {
            throw A2aRpcException::taskNotFound($id);
        }

        return $this->task($inbound, $this->stateFor($inbound));
    }

    private function stateFor(AgentChatMessage $inbound): string
    {
        if ($this->replyTo($inbound) !== null) {
            return 'completed';
        }

        return $inbound->getAttribute('status') === MessageStatus::Failed ? 'failed' : 'working';
    }

    private function replyTo(AgentChatMessage $inbound): ?AgentChatMessage
    {
        return AgentChatMessage::withoutGlobalScopes()
            ->where('agent_id', $inbound->agent_id)
            ->where('direction', MessageDirection::Outbound)
            ->where('in_reply_to', $inbound->msg_id)
            ->latest('created_at')
            ->first();
    }

    /**
     * Build the A2A Task object for an inbound message in a given state.
     *
     * @return array<string, mixed>
     */
    private function task(AgentChatMessage $inbound, string $state): array
    {
        $reply = $state === 'completed' ? $this->replyTo($inbound) : null;

        // Echo back the token the peer used as contextId; fall back to the row
        // id if the session relation is not loaded.
        $session = $inbound->getAttribute('session');
        $contextId = $session !== null ? (string) $session->session_token : (string) $inbound->session_id;

        $task = [
            'kind' => 'task',
            'id' => (string) $inbound->msg_id,
            'contextId' => $contextId,
            'status' => [
                'state' => $state,
                'timestamp' => now()->toIso8601String(),
            ],
            'history' => [$this->messageObject('user', $this->contentOf($inbound), (string) $inbound->msg_id)],
        ];

        if ($state === 'failed') {
            $task['status']['message'] = $this->messageObject(
                'agent',
                (string) ($inbound->getAttribute('error') ?? 'Agent execution failed.'),
                Str::uuid7()->toString(),
            );
        }

        if ($reply !== null) {
            $text = $this->contentOf($reply);
            $task['artifacts'] = [[
                'artifactId' => (string) $reply->msg_id,
                'name' => 'reply',
                'parts' => [['kind' => 'text', 'text' => $text]],
            ]];
            $task['history'][] = $this->messageObject('agent', $text, (string) $reply->msg_id);
        }

        return $task;
    }

    /**
     * The `payload` column is a JSON cast; reading it through getAttribute
     * keeps the array shape honest for static analysis.
     */
    private function contentOf(AgentChatMessage $message): string
    {
        return (string) (((array) $message->getAttribute('payload'))['content'] ?? '');
    }

    /**
     * @return array<string, mixed>
     */
    private function messageObject(string $role, string $text, string $messageId): array
    {
        return [
            'kind' => 'message',
            'role' => $role,
            'messageId' => $messageId,
            'parts' => [['kind' => 'text', 'text' => $text]],
        ];
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private function textFromParts(array $parts): string
    {
        $chunks = [];
        foreach ($parts as $part) {
            if (is_array($part) && ($part['kind'] ?? null) === 'text' && isset($part['text'])) {
                $chunks[] = (string) $part['text'];
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @return array<string, mixed>
     */
    private function error(mixed $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }
}
