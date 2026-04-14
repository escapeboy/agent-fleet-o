<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Shared\Models\ContactIdentity;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use App\Mcp\Attributes\AssistantTool;

#[IsReadOnly]
#[AssistantTool('read')]
class ListHighRiskContactsTool extends Tool
{
    protected string $name = 'contact_high_risk_list';

    protected string $description = 'List contact identities whose risk score is at or above a threshold, ordered by highest score first.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'threshold' => $schema->integer()
                ->description('Minimum risk score to include (default: 30)')
                ->default(30),
            'limit' => $schema->integer()
                ->description('Maximum number of results (default: 25, max: 100)')
                ->default(25),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;

        if (! $teamId) {
            return Response::error('No current team.');
        }

        $threshold = min(max((int) $request->get('threshold', 30), 0), 1000);
        $limit = min((int) $request->get('limit', 25), 100);

        $contacts = ContactIdentity::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('risk_score', '>=', $threshold)
            ->orderByDesc('risk_score')
            ->limit($limit)
            ->get(['id', 'display_name', 'email', 'phone', 'risk_score', 'risk_flags', 'risk_evaluated_at']);

        return Response::text(json_encode([
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
        ]));
    }
}
