<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListHighRiskContactsTool implements Tool
{
    public function name(): string
    {
        return 'list_high_risk_contacts';
    }

    public function description(): string
    {
        return 'List contact identities whose risk score is at or above a threshold, ordered by highest score first';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'threshold' => $schema->integer()->description('Minimum risk score to include (default: 30)'),
            'limit' => $schema->integer()->description('Maximum number of results to return (default: 25, max: 100)'),
        ];
    }

    public function handle(Request $request): string
    {
        $teamId = Auth::user()?->current_team_id;

        if (! $teamId) {
            return json_encode(['error' => 'No current team.']);
        }

        $threshold = min(max($request->get('threshold', 30), 0), 1000);
        $limit = min($request->get('limit', 25), 100);

        $contacts = ContactIdentity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('risk_score', '>=', $threshold)
            ->orderByDesc('risk_score')
            ->limit($limit)
            ->get(['id', 'display_name', 'email', 'phone', 'risk_score', 'risk_flags', 'risk_evaluated_at']);

        return json_encode([
            'threshold' => $threshold,
            'total' => $contacts->count(),
            'contacts' => $contacts->map(fn ($c) => [
                'id' => $c->id,
                'display_name' => $c->display_name,
                'email' => $c->email,
                'phone' => $c->phone,
                'risk_score' => $c->risk_score,
                'risk_flags' => $c->risk_flags ?? [],
                'risk_evaluated_at' => $c->risk_evaluated_at?->toIso8601String(),
            ])->values()->toArray(),
        ]);
    }
}
