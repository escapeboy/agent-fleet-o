<?php

namespace App\Mcp\Tools\ErrorMode;

use App\Domain\ErrorMode\Models\ErrorMode;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ErrorModeListTool extends Tool
{
    protected string $name = 'error_mode_list';

    protected string $description = 'List the team error-mode catalog (named production failure modes), ranked by occurrence, with their assigned remediation lever and status.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'status' => $schema->string()
                ->description('Filter by status: open, mitigated, closed')
                ->enum(['open', 'mitigated', 'closed']),
            'lever' => $schema->string()
                ->description('Filter by assigned lever (e.g. retrieval, prompt, guardrails, unassigned)'),
            'limit' => $schema->integer()
                ->description('Max rows (default 50)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::text(json_encode(['error' => 'No current team.']));
        }

        $modes = ErrorMode::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->when($request->get('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->get('lever'), fn ($q, $l) => $q->where('lever', $l))
            ->orderByDesc('occurrence_count')
            ->limit(min(200, max(1, (int) $request->get('limit', 50))))
            ->get(['id', 'slug', 'name', 'lever', 'status', 'occurrence_count', 'first_seen_at', 'last_seen_at']);

        return Response::text(json_encode(['error_modes' => $modes->toArray()]));
    }
}
