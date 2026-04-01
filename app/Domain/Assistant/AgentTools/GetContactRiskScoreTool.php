<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class GetContactRiskScoreTool implements Tool
{
    public function name(): string
    {
        return 'get_contact_risk_score';
    }

    public function description(): string
    {
        return 'Get the current risk score, triggered rule flags, and evaluation timestamp for a specific contact identity';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'contact_id' => $schema->string()->required()->description('The UUID of the contact identity'),
        ];
    }

    public function handle(Request $request): string
    {
        $teamId = Auth::user()?->current_team_id;

        if (! $teamId) {
            return json_encode(['error' => 'No current team.']);
        }

        $contact = ContactIdentity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->find($request->get('contact_id'));

        if (! $contact) {
            return json_encode(['error' => 'Contact not found']);
        }

        return json_encode([
            'contact_id' => $contact->id,
            'display_name' => $contact->display_name,
            'risk_score' => $contact->risk_score,
            'risk_flags' => $contact->risk_flags ?? [],
            'risk_evaluated_at' => $contact->risk_evaluated_at?->toIso8601String(),
        ]);
    }
}
