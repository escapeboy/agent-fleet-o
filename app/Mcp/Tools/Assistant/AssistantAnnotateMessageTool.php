<?php

namespace App\Mcp\Tools\Assistant;

use App\Domain\Assistant\Actions\AnnotateMessageAction;
use App\Domain\Assistant\Enums\AnnotationRating;
use App\Domain\Assistant\Models\AssistantMessage;
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
class AssistantAnnotateMessageTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'assistant_annotate_message';

    protected string $description = 'Rate an assistant message as positive or negative, with an optional corrected response. Used to provide feedback on AI quality.';

    public function __construct(private readonly AnnotateMessageAction $action) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'message_id' => $schema->string()
                ->description('UUID of the assistant message to annotate')
                ->required(),
            'rating' => $schema->string()
                ->description('Feedback rating: positive (good response) or negative (bad response)')
                ->required(),
            'correction' => $schema->string()
                ->description('Optional corrected/preferred response text'),
            'note' => $schema->string()
                ->description('Optional internal note explaining the rating'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? null;

        if (! $teamId) {
            return $this->permissionDeniedError('No team context.');
        }

        $messageId = $request->get('message_id');
        $ratingValue = $request->get('rating');

        if (! $messageId || ! $ratingValue) {
            return $this->invalidArgumentError('message_id and rating are required.');
        }

        $rating = AnnotationRating::tryFrom($ratingValue);

        if (! $rating) {
            return $this->invalidArgumentError('rating must be "positive" or "negative".');
        }

        $message = AssistantMessage::withoutGlobalScopes()
            ->whereHas('conversation', fn ($q) => $q->withoutGlobalScopes()->where('team_id', $teamId))
            ->where('id', $messageId)
            ->first();

        if (! $message) {
            return $this->notFoundError('message');
        }

        // Use team owner as acting user in MCP context.
        $userId = User::whereHas('teams', fn ($q) => $q->where('teams.id', $teamId)
            ->where('team_user.role', 'owner'),
        )->value('id');

        if (! $userId) {
            return $this->permissionDeniedError('Could not resolve team owner.');
        }

        $annotation = $this->action->execute(
            message: $message,
            userId: $userId,
            rating: $rating,
            correction: $request->get('correction'),
            note: $request->get('note'),
        );

        return Response::text(json_encode([
            'id' => $annotation->id,
            'message_id' => $annotation->message_id,
            'rating' => $annotation->rating->value,
            'correction' => $annotation->correction,
        ]));
    }
}
