<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Shared\Models\ContactIdentity;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class GetContactRiskScoreTool extends Tool
{
    protected string $name = 'contact_risk_score_get';

    protected string $description = 'Get the current risk score and triggered rule flags for a contact identity.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->string()
                ->description('Contact identity UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $contact = ContactIdentity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('contact_id'));

        if (! $contact) {
            return Response::error('Contact not found.');
        }

        return Response::text(json_encode([
            'contact_id' => $contact->id,
            'risk_score' => $contact->risk_score,
            'risk_flags' => $contact->risk_flags ?? [],
            'risk_evaluated_at' => $contact->risk_evaluated_at?->toIso8601String(),
        ]));
    }
}
