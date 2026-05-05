<?php

namespace App\Mcp\Tools\Assistant;

use App\Domain\Assistant\Actions\ReviewAssistantConversationAction;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class AssistantReviewConversationTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'assistant_review_conversation';

    protected string $description = 'Review the quality of an assistant conversation. Evaluates question quality, goal alignment, ambiguity resolution, and sycophancy detection. Returns a scored rubric.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'conversation_id' => $schema->string()
                ->description('UUID of the conversation to review')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $conversation = AssistantConversation::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('id', $request->get('conversation_id'))
            ->first();

        if (! $conversation) {
            return $this->notFoundError('conversation');
        }

        $action = app(ReviewAssistantConversationAction::class);
        $review = $action->execute($conversation);

        return Response::text(json_encode($review));
    }
}
