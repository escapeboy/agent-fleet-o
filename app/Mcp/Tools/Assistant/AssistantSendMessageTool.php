<?php

namespace App\Mcp\Tools\Assistant;

use App\Domain\Assistant\Actions\SendAssistantMessageAction;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class AssistantSendMessageTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'assistant_send_message';

    protected string $description = 'Send a message to the AI assistant and receive a reply. Creates a new conversation if no conversation_id is provided.';

    public function __construct(private readonly SendAssistantMessageAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'message' => $schema->string()
                ->description('The message to send to the assistant')
                ->required(),
            'conversation_id' => $schema->string()
                ->description('Existing conversation UUID (omit to auto-create)'),
            'context_type' => $schema->string()
                ->description('Context binding: experiment | project | agent | crew | workflow'),
            'context_id' => $schema->string()
                ->description('UUID of the bound context entity'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $message = $request->get('message');

        if (! $message) {
            return $this->invalidArgumentError('message is required.');
        }

        // Resolve or create a conversation.
        $conversationId = $request->get('conversation_id');

        if ($conversationId) {
            $conversation = AssistantConversation::withoutGlobalScopes()
                ->where('team_id', $teamId)
                ->where('id', $conversationId)
                ->first();

            if (! $conversation) {
                return $this->notFoundError('conversation');
            }
        } else {
            $conversation = AssistantConversation::withoutGlobalScopes()->create([
                'team_id' => $teamId,
                'user_id' => null, // MCP context — no user
                'title' => mb_strimwidth($message, 0, 80, '…'),
                'context_type' => $request->get('context_type'),
                'context_id' => $request->get('context_id'),
            ]);
        }

        // Resolve team owner as the acting user for tool permission checks.
        $user = User::whereHas('teams', fn ($q) => $q->where('teams.id', $teamId)
            ->where('team_user.role', 'owner'),
        )->first();

        if (! $user) {
            return $this->permissionDeniedError('Could not resolve team owner for assistant context.');
        }

        try {
            $aiResponse = $this->action->execute(
                conversation: $conversation,
                userMessage: $message,
                user: $user,
                contextType: $request->get('context_type', $conversation->context_type),
                contextId: $request->get('context_id', $conversation->context_id),
            );

            $reply = $conversation->messages()
                ->where('role', 'assistant')
                ->latest('created_at')
                ->first();

            return Response::text(json_encode([
                'conversation_id' => $conversation->id,
                'reply' => $reply?->content,
                'total_tokens' => $aiResponse->usage->totalTokens ?? null,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
