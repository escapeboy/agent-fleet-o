<?php

namespace App\Mcp\Tools\Audience;

use App\Domain\Audience\Actions\AddAudienceMember;
use App\Domain\Audience\Models\Audience;
use App\Domain\Shared\Models\ContactIdentity;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
// @mcp-cross-tenant transitive-via-audience
class AudienceAddMemberTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'audience_add_member';

    protected string $description = 'Add a contact (by email) to an audience, or re-subscribe them if they had previously unsubscribed. Creates the contact if it does not exist.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'audience_id' => $schema->string()
                ->description('The audience UUID')
                ->required(),
            'email' => $schema->string()
                ->description('Contact email address')
                ->required(),
            'display_name' => $schema->string()
                ->description('Optional display name for a newly created contact'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'audience_id' => 'required|string',
            'email' => 'required|email',
            'display_name' => 'nullable|string|max:255',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->failedPreconditionError('No team context available.');
        }

        $audience = Audience::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($validated['audience_id']);

        if (! $audience) {
            return $this->notFoundError('audience', $validated['audience_id']);
        }

        $contact = ContactIdentity::withoutGlobalScopes()->firstOrCreate(
            ['team_id' => $teamId, 'email' => $validated['email']],
            ['display_name' => $validated['display_name'] ?? null],
        );

        $member = app(AddAudienceMember::class)->execute($audience, $contact);

        return Response::text(json_encode([
            'audience_id' => $audience->id,
            'contact_identity_id' => $contact->id,
            'email' => $contact->email,
            'status' => $member->status->value,
        ]));
    }
}
