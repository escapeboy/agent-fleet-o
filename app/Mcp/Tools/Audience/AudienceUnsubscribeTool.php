<?php

namespace App\Mcp\Tools\Audience;

use App\Domain\Audience\Actions\UnsubscribeContact;
use App\Domain\Shared\Models\ContactIdentity;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class AudienceUnsubscribeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'audience_unsubscribe';

    protected string $description = 'Unsubscribe a contact (by email) from one audience, or from every audience when no audience_id is given.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'email' => $schema->string()
                ->description('Contact email address')
                ->required(),
            'audience_id' => $schema->string()
                ->description('Optional audience UUID — omit to unsubscribe from all audiences'),
            'reason' => $schema->string()
                ->description('Optional unsubscribe reason'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'audience_id' => 'nullable|string',
            'reason' => 'nullable|string|max:255',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->failedPreconditionError('No team context available.');
        }

        $contact = ContactIdentity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('email', $validated['email'])
            ->first();

        if (! $contact) {
            return $this->notFoundError('contact', $validated['email']);
        }

        $count = app(UnsubscribeContact::class)->execute(
            teamId: $teamId,
            contact: $contact,
            audienceId: $validated['audience_id'] ?? null,
            reason: $validated['reason'] ?? null,
        );

        return Response::text(json_encode([
            'email' => $contact->email,
            'unsubscribed_count' => $count,
        ]));
    }
}
