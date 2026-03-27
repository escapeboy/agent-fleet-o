<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\SendAgentMessageAction;
use App\Domain\Crew\Models\CrewExecution;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class CrewSendMessageTool extends Tool
{
    protected string $name = 'crew_send_message';

    protected string $description = 'Send a message from one crew agent to another within a crew execution. Use message_type "finding" to publish results, "query" to ask a question, "broadcast" to send to all.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_execution_id' => $schema->string()
                ->description('The crew execution UUID')
                ->required(),
            'message_type' => $schema->string()
                ->description('Type of message: finding, challenge, query, broadcast, or system')
                ->enum(['finding', 'challenge', 'query', 'broadcast', 'system'])
                ->required(),
            'content' => $schema->string()
                ->description('The message content')
                ->required(),
            'sender_agent_id' => $schema->string()
                ->description('UUID of the sending agent (optional)'),
            'recipient_agent_id' => $schema->string()
                ->description('UUID of the recipient agent (optional — omit for broadcasts)'),
            'round' => $schema->integer()
                ->description('Debate/execution round number (default: 0)'),
            'parent_message_id' => $schema->string()
                ->description('UUID of the parent message for threading (optional)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'crew_execution_id' => 'required|string',
            'message_type' => 'required|string|in:finding,challenge,query,broadcast,system',
            'content' => 'required|string',
            'sender_agent_id' => 'nullable|string',
            'recipient_agent_id' => 'nullable|string',
            'round' => 'nullable|integer',
            'parent_message_id' => 'nullable|string',
        ]);

        $execution = CrewExecution::find($validated['crew_execution_id']);

        if (! $execution) {
            return Response::error('Crew execution not found.');
        }

        $sender = isset($validated['sender_agent_id'])
            ? Agent::withoutGlobalScopes()->find($validated['sender_agent_id'])
            : null;

        $recipient = isset($validated['recipient_agent_id'])
            ? Agent::withoutGlobalScopes()->find($validated['recipient_agent_id'])
            : null;

        try {
            $message = app(SendAgentMessageAction::class)->execute(
                execution: $execution,
                messageType: $validated['message_type'],
                content: $validated['content'],
                sender: $sender,
                recipient: $recipient,
                round: $validated['round'] ?? 0,
                parentMessageId: $validated['parent_message_id'] ?? null,
            );

            return Response::text(json_encode([
                'success' => true,
                'message_id' => $message->id,
                'message_type' => $message->message_type,
                'round' => $message->round,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
