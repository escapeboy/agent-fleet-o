<?php

namespace App\Mcp\Tools\Shared;

use App\Infrastructure\Telemetry\TenantTracerProviderFactory;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Crypt;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class TeamObservabilityUpdateTool extends Tool
{
    protected string $name = 'team_observability_update';

    protected string $description = 'Configure the current team\'s OTLP export destination. Enables streaming OpenTelemetry traces (LLM calls, agent runs, MCP tool invocations) to a team-owned backend like Logfire, Honeycomb, or Grafana Tempo. Requires manage-team role. Clearing the token sets it to empty and keeps other fields; pass reset=true to disable entirely.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'enabled' => $schema->boolean()->description('Toggle OTLP export for this team')->default(true),
            'endpoint' => $schema->string()->description('OTLP HTTP base URL (e.g. https://logfire-api.pydantic.dev). /v1/traces is appended automatically.'),
            'token' => $schema->string()->description('Bearer token or full "Bearer xxx" line. Stored encrypted. Omit to keep existing token.'),
            'sample_rate' => $schema->number()->description('Fraction of spans to export (0.0–1.0, default 1.0)'),
            'service_name' => $schema->string()->description('Optional service.name resource attribute. Helps distinguish teams in shared dashboards.'),
            'reset' => $schema->boolean()->description('If true, clears all observability settings and disables export. Ignores other fields when set.')->default(false),
        ];
    }

    public function handle(Request $request, TenantTracerProviderFactory $factory): Response
    {
        $user = auth()->user();
        if (! $user || ! $user->currentTeam) {
            return Response::error('Authentication or team context missing');
        }

        if (! $user->can('manage-team', $user->currentTeam)) {
            return Response::error('You do not have permission to manage team settings (requires owner or admin).');
        }

        $team = $user->currentTeam;
        $settings = $team->settings ?? [];

        if ($request->get('reset') === true) {
            unset($settings['observability']);
            $team->update(['settings' => $settings]);
            $factory->forget($team->id);

            return Response::text(json_encode([
                'status' => 'reset',
                'message' => 'Observability settings cleared.',
            ]));
        }

        $current = $settings['observability'] ?? [];
        $next = [
            'enabled' => (bool) $request->get('enabled', $current['enabled'] ?? false),
            'endpoint' => trim((string) $request->get('endpoint', $current['endpoint'] ?? '')),
            'sample_rate' => max(0.0, min(1.0, (float) $request->get('sample_rate', $current['sample_rate'] ?? 1.0))),
            'service_name' => trim((string) $request->get('service_name', $current['service_name'] ?? '')),
            'otlp_token_encrypted' => $current['otlp_token_encrypted'] ?? '',
        ];

        $token = $request->get('token');
        if (is_string($token) && $token !== '') {
            $next['otlp_token_encrypted'] = Crypt::encryptString($token);
        }

        if ($next['enabled'] && $next['endpoint'] === '') {
            return Response::error('endpoint is required when enabled=true');
        }

        $settings['observability'] = $next;
        $team->update(['settings' => $settings]);
        $factory->forget($team->id);

        return Response::text(json_encode([
            'status' => 'saved',
            'enabled' => $next['enabled'],
            'endpoint' => $next['endpoint'],
            'sample_rate' => $next['sample_rate'],
            'service_name' => $next['service_name'],
            'token_configured' => $next['otlp_token_encrypted'] !== '',
        ]));
    }
}
