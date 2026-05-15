<?php

namespace Tests\Feature\Mcp;

use App\Domain\Integration\Enums\IntegrationStatus;
use App\Domain\Integration\Models\Integration;
use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Jobs\RunSentryWatchdogJob;
use App\Domain\Signal\Models\SentryWatchdogRun;
use App\Mcp\Tools\Signal\SentryWatchdogRunTool;
use App\Mcp\Tools\Signal\SentryWatchdogStatusTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

/**
 * Multi-tenancy coverage for the Sentry Watchdog MCP tools — verifies the
 * team scope is unconditional (no cross-tenant leakage) and that an
 * unresolved team context fails closed rather than falling open to all teams.
 */
class SentryWatchdogMcpToolsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    private function makeRun(string $teamId, string $integrationId): SentryWatchdogRun
    {
        return SentryWatchdogRun::create([
            'integration_id' => $integrationId,
            'team_id' => $teamId,
            'started_at' => now(),
            'finished_at' => now(),
            'signals_triaged' => 1,
            'prs_opened' => 0,
            'investigate_only' => 1,
            'critical_count' => 0,
        ]);
    }

    public function test_status_tool_returns_only_the_bound_teams_runs(): void
    {
        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $intA = Integration::factory()->create(['team_id' => $teamA->id, 'driver' => 'sentry']);
        $intB = Integration::factory()->create(['team_id' => $teamB->id, 'driver' => 'sentry']);
        $this->makeRun($teamA->id, $intA->id);
        $this->makeRun($teamB->id, $intB->id);

        app()->instance('mcp.team_id', $teamA->id);

        $result = $this->decode(app(SentryWatchdogStatusTool::class)->handle(new Request([])));

        $this->assertSame(1, $result['count']);
        $this->assertSame($intA->id, $result['runs'][0]['integration_id']);
    }

    public function test_status_tool_fails_closed_when_team_context_is_unresolved(): void
    {
        $team = Team::factory()->create();
        $int = Integration::factory()->create(['team_id' => $team->id, 'driver' => 'sentry']);
        $this->makeRun($team->id, $int->id);

        app()->instance('mcp.team_id', null);

        $result = $this->decode(app(SentryWatchdogStatusTool::class)->handle(new Request([])));

        $this->assertArrayHasKey('error', $result);
        $this->assertArrayNotHasKey('runs', $result);
    }

    public function test_run_tool_dispatches_only_the_bound_teams_enabled_integrations(): void
    {
        Queue::fake();

        $teamA = Team::factory()->create();
        $teamB = Team::factory()->create();
        $intA = Integration::factory()->create([
            'team_id' => $teamA->id,
            'driver' => 'sentry',
            'status' => IntegrationStatus::Active,
            'config' => ['watchdog_enabled' => true],
        ]);
        Integration::factory()->create([
            'team_id' => $teamB->id,
            'driver' => 'sentry',
            'status' => IntegrationStatus::Active,
            'config' => ['watchdog_enabled' => true],
        ]);

        app()->instance('mcp.team_id', $teamA->id);

        $result = $this->decode(app(SentryWatchdogRunTool::class)->handle(new Request([])));

        $this->assertSame(1, $result['dispatched']);
        Queue::assertPushed(RunSentryWatchdogJob::class, 1);
        Queue::assertPushed(RunSentryWatchdogJob::class, fn (RunSentryWatchdogJob $job) => $job->integrationId === $intA->id);
    }

    public function test_run_tool_fails_closed_when_team_context_is_unresolved(): void
    {
        Queue::fake();

        $team = Team::factory()->create();
        Integration::factory()->create([
            'team_id' => $team->id,
            'driver' => 'sentry',
            'status' => IntegrationStatus::Active,
            'config' => ['watchdog_enabled' => true],
        ]);

        app()->instance('mcp.team_id', null);

        $result = $this->decode(app(SentryWatchdogRunTool::class)->handle(new Request([])));

        $this->assertArrayHasKey('error', $result);
        Queue::assertNothingPushed();
    }
}
