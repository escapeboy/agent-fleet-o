<?php

namespace App\Mcp\Tools\Shared;

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
class TeamObservabilityGetTool extends Tool
{
    protected string $name = 'team_observability_get';

    protected string $description = 'Read the current team\'s OTLP observability export settings. Returns endpoint, sample rate, service name, enabled flag, and whether a bearer token is configured (token itself never leaves the server).';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $user = auth()->user();
        if (! $user || ! $user->currentTeam) {
            return Response::error('Authentication or team context missing');
        }

        $settings = $user->currentTeam->settings ?? [];
        $observability = $settings['observability'] ?? [];

        return Response::text(json_encode([
            'enabled' => (bool) ($observability['enabled'] ?? false),
            'endpoint' => (string) ($observability['endpoint'] ?? ''),
            'sample_rate' => (float) ($observability['sample_rate'] ?? 1.0),
            'service_name' => (string) ($observability['service_name'] ?? ''),
            'token_configured' => ! empty($observability['otlp_token_encrypted']),
            'deployment_environment' => (string) ($observability['deployment_environment'] ?? config('telemetry.deployment_environment', 'production')),
        ]));
    }
}
