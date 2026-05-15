<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Signal\Jobs\RunSentryWatchdogJob;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

#[AssistantTool('write')]
class SentryWatchdogRunTool extends Tool
{
    protected string $name = 'sentry_watchdog_run';

    protected string $description = 'Manually trigger a Sentry Watchdog triage batch for the current team\'s enabled Sentry integrations. Optionally filter by integration name.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'project' => $schema->string()
                ->description('Only run integrations whose name contains this value'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null)
            ?? auth()->user()?->current_team_id;

        if ($teamId === null) {
            return Response::text((string) json_encode(['error' => 'No team context resolved.']));
        }

        $project = $request->get('project');

        $integrations = Integration::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('driver', 'sentry')
            ->where('status', IntegrationStatus::Active)
            ->when(
                $project,
                fn ($query, $value) => $query->whereRaw('LOWER(name) LIKE ?', ['%'.strtolower((string) $value).'%']),
            )
            ->get()
            ->filter(fn (Integration $integration) => (bool) ($integration->config['watchdog_enabled'] ?? false));

        foreach ($integrations as $integration) {
            RunSentryWatchdogJob::dispatch($integration->id);
        }

        return Response::text((string) json_encode([
            'dispatched' => $integrations->count(),
            'integrations' => $integrations->pluck('name')->values()->toArray(),
            'message' => $integrations->isEmpty()
                ? 'No enabled Sentry integrations found to run.'
                : 'Sentry Watchdog batch dispatched.',
        ], JSON_PRETTY_PRINT));
    }
}
