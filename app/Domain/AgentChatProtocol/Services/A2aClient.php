<?php

declare(strict_types=1);

namespace App\Domain\AgentChatProtocol\Services;

use App\Domain\AgentChatProtocol\Models\ExternalAgent;
use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Minimal A2A (agent-to-agent) JSON-RPC 2.0 client. Speaks `message/send` and
 * polls `tasks/get` for long-running Tasks. The peer endpoint is the AgentCard
 * `url` stored on ExternalAgent.endpoint_url; auth (when present) is the
 * encrypted credential carried as a Bearer token, matching the `Bearer` scheme
 * FleetQ advertises on its own card.
 */
class A2aClient
{
    /** Task states that mean we should stop polling. */
    private const TERMINAL_STATES = ['completed', 'failed', 'canceled', 'cancelled', 'rejected', 'input-required', 'unknown'];

    public function __construct(private readonly SsrfGuard $ssrfGuard) {}

    /**
     * Send a single text message to an A2A peer and resolve its reply,
     * polling tasks/get if the peer returns a long-running Task.
     *
     * @return array{msg_id: string, from: string, content: string, a2a: array<string, mixed>}
     */
    public function sendMessage(ExternalAgent $externalAgent, string $text, ?string $messageId = null): array
    {
        $url = (string) $externalAgent->endpoint_url;
        // External, tenant-supplied endpoint — must be SSRF-guarded on every call.
        $this->ssrfGuard->assertPublicUrl($url);

        $messageId ??= Str::uuid7()->toString();

        $result = $this->rpc($externalAgent, $url, 'message/send', [
            'message' => [
                'role' => 'user',
                'kind' => 'message',
                'messageId' => $messageId,
                'parts' => [['kind' => 'text', 'text' => $text]],
            ],
        ]);

        return $this->resolveResult($externalAgent, $url, $result);
    }

    /**
     * @param  array<string, mixed>  $result  JSON-RPC result (Message or Task).
     * @return array{msg_id: string, from: string, content: string, a2a: array<string, mixed>}
     */
    private function resolveResult(ExternalAgent $externalAgent, string $url, array $result): array
    {
        $kind = (string) ($result['kind'] ?? (isset($result['status']) ? 'task' : 'message'));

        if ($kind === 'message') {
            return $this->normalize($externalAgent, $result, $this->textFromMessage($result));
        }

        // Task: poll until a terminal state or the attempt budget is exhausted.
        $taskId = (string) ($result['id'] ?? '');
        $attempts = max(0, (int) config('agent_chat.a2a.task_poll_attempts', 5));
        $delayMs = max(0, (int) config('agent_chat.a2a.task_poll_delay_ms', 1000));

        for ($i = 0; $i < $attempts && $taskId !== '' && ! $this->isTerminal($result); $i++) {
            usleep($delayMs * 1000);
            $result = $this->rpc($externalAgent, $url, 'tasks/get', ['id' => $taskId]);
        }

        $state = (string) ($result['status']['state'] ?? 'unknown');
        if (in_array($state, ['failed', 'rejected', 'canceled', 'cancelled'], true)) {
            $reason = $this->textFromMessage($result['status']['message'] ?? []) ?: $state;
            throw new \RuntimeException("A2A task {$taskId} ended in state '{$state}': {$reason}");
        }

        return $this->normalize($externalAgent, $result, $this->textFromTask($result));
    }

    /**
     * Issue a JSON-RPC call and return the `result` member, throwing on a
     * transport error or a JSON-RPC `error` member.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function rpc(ExternalAgent $externalAgent, string $url, string $method, array $params): array
    {
        $id = Str::uuid7()->toString();
        $response = $this->request($externalAgent)->post($url, [
            'jsonrpc' => '2.0',
            'id' => $id,
            'method' => $method,
            'params' => $params,
        ]);

        if ($response->status() >= 400) {
            throw new \RuntimeException("A2A {$method} returned HTTP {$response->status()}: ".$response->body());
        }

        $body = (array) $response->json();

        if (isset($body['error'])) {
            $message = (string) ($body['error']['message'] ?? 'unknown error');
            $code = $body['error']['code'] ?? '';

            throw new \RuntimeException("A2A {$method} JSON-RPC error {$code}: {$message}");
        }

        return (array) ($body['result'] ?? []);
    }

    private function request(ExternalAgent $externalAgent): PendingRequest
    {
        $timeout = min(
            (int) config('agent_chat.outbound.timeout_seconds', 30),
            (int) config('agent_chat.outbound.max_timeout_seconds', 120),
        );

        $request = Http::timeout($timeout)
            ->acceptJson()
            ->withHeaders(['User-Agent' => 'FleetQ-A2A/1.0']);

        if ($externalAgent->credential_id !== null && $externalAgent->credential !== null) {
            $secret = $externalAgent->credential->secret_data['value'] ?? null;
            if ($secret !== null) {
                $request = $request->withToken((string) $secret);
            }
        }

        return $request;
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private function isTerminal(array $task): bool
    {
        return in_array((string) ($task['status']['state'] ?? ''), self::TERMINAL_STATES, true);
    }

    /**
     * Pull display text out of a Task: prefer the latest artifact, then the
     * status message, then the last agent turn in history.
     *
     * @param  array<string, mixed>  $task
     */
    private function textFromTask(array $task): string
    {
        foreach (array_reverse((array) ($task['artifacts'] ?? [])) as $artifact) {
            $text = $this->textFromParts((array) ($artifact['parts'] ?? []));
            if ($text !== '') {
                return $text;
            }
        }

        $statusText = $this->textFromMessage((array) ($task['status']['message'] ?? []));
        if ($statusText !== '') {
            return $statusText;
        }

        foreach (array_reverse((array) ($task['history'] ?? [])) as $message) {
            if ((string) (($message['role'] ?? '')) === 'agent') {
                return $this->textFromMessage((array) $message);
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $message
     */
    private function textFromMessage(array $message): string
    {
        return $this->textFromParts((array) ($message['parts'] ?? []));
    }

    /**
     * @param  array<int, mixed>  $parts
     */
    private function textFromParts(array $parts): string
    {
        $chunks = [];
        foreach ($parts as $part) {
            if (! is_array($part)) {
                continue;
            }
            if (($part['kind'] ?? null) === 'text' && isset($part['text'])) {
                $chunks[] = (string) $part['text'];
            }
        }

        return trim(implode("\n", $chunks));
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array{msg_id: string, from: string, content: string, a2a: array<string, mixed>}
     */
    private function normalize(ExternalAgent $externalAgent, array $result, string $content): array
    {
        return [
            'msg_id' => (string) ($result['messageId'] ?? $result['id'] ?? Str::uuid7()->toString()),
            'from' => (string) $externalAgent->slug,
            'content' => $content,
            'a2a' => $result,
        ];
    }
}
