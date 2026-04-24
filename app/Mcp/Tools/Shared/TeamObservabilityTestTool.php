<?php

namespace App\Mcp\Tools\Shared;

use App\Infrastructure\Telemetry\TenantTracerTester;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[AssistantTool('read')]
class TeamObservabilityTestTool extends Tool
{
    protected string $name = 'team_observability_test';

    protected string $description = 'Test the team\'s current OTLP observability endpoint + token without saving anything. Sends a minimal probe to <endpoint>/v1/traces and returns status (ok | auth_failed | endpoint_not_found | unreachable | ...) + latency. Use after team_observability_update to verify the new config works.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request, TenantTracerTester $tester): Response
    {
        $user = auth()->user();
        if (! $user || ! $user->currentTeam) {
            return Response::error('Authentication or team context missing');
        }

        $settings = $user->currentTeam->settings ?? [];
        $observability = $settings['observability'] ?? [];

        if (($observability['enabled'] ?? false) === false) {
            return Response::text(json_encode([
                'ok' => false,
                'status' => 'disabled',
                'message' => 'Observability is not enabled for this team. Call team_observability_update first.',
            ]));
        }

        $result = $tester->test($observability);

        return Response::text(json_encode($result));
    }
}
