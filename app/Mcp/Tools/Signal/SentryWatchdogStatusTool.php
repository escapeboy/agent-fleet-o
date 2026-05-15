<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
#[AssistantTool('read')]
class SentryWatchdogStatusTool extends Tool
{
    protected string $name = 'sentry_watchdog_status';

    protected string $description = 'Show recent Sentry Watchdog runs for the current team — per-run counts of issues triaged, PRs opened, investigate-only outcomes, and critical alerts.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'limit' => $schema->integer()
                ->description('Max runs to return (default 10, max 50)')
                ->default(10),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null)
            ?? auth()->user()?->current_team_id;

        if ($teamId === null) {
            return Response::text((string) json_encode(['error' => 'No team context resolved.']));
        }

        $runs = SentryWatchdogRun::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->orderByDesc('started_at')
            ->limit(min((int) $request->get('limit', 10), 50))
            ->get();

        return Response::text((string) json_encode([
            'count' => $runs->count(),
            'runs' => $runs->map(fn (SentryWatchdogRun $run) => [
                'id' => $run->id,
                'integration_id' => $run->integration_id,
                'started_at' => $run->started_at->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
                'signals_triaged' => $run->signals_triaged,
                'prs_opened' => $run->prs_opened,
                'investigate_only' => $run->investigate_only,
                'critical_count' => $run->critical_count,
                'digest_summary' => $run->digest_summary,
            ])->toArray(),
        ], JSON_PRETTY_PRINT));
    }
}
