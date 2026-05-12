<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Observability;

use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Models\CircuitBreakerState;
use App\Infrastructure\Observability\Alerts\AlertEvaluator;
use App\Infrastructure\Observability\Alerts\PlatformAlertTriggered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class AlertEvaluatorBreakerStalenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Cache::store('redis')->flush();

        Config::set('observability.alerts.thresholds', [
            'queue_depth' => 0,
            'error_rate_per_minute' => 0,
            'p95_llm_latency_ms' => 0,
            'stuck_experiments' => 0,
            'circuit_breaker_open' => 1,
        ]);
        Config::set('observability.alerts.prometheus_api_url', '');
        Config::set('observability.alerts.breaker_stale_after_seconds', 3600);
    }

    public function test_stale_open_breakers_do_not_trigger_alert(): void
    {
        Event::fake([PlatformAlertTriggered::class]);

        $team = Team::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        CircuitBreakerState::create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'state' => 'open',
            'failure_count' => 6,
            'success_count' => 0,
            'last_failure_at' => now()->subDays(16),
            'opened_at' => now()->subDays(16),
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
        ]);

        app(AlertEvaluator::class)->evaluate();

        Event::assertNotDispatched(PlatformAlertTriggered::class);
    }

    public function test_fresh_open_breakers_still_trigger_alert(): void
    {
        Event::fake([PlatformAlertTriggered::class]);

        $team = Team::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $team->id]);

        CircuitBreakerState::create([
            'team_id' => $team->id,
            'agent_id' => $agent->id,
            'state' => 'open',
            'failure_count' => 6,
            'success_count' => 0,
            'last_failure_at' => now()->subMinutes(5),
            'opened_at' => now()->subMinutes(5),
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
        ]);

        app(AlertEvaluator::class)->evaluate();

        Event::assertDispatched(
            PlatformAlertTriggered::class,
            fn (PlatformAlertTriggered $event) => $event->rule->metricName === 'circuit_breaker_open'
                && (int) $event->currentValue === 1,
        );
    }

    public function test_mixed_stale_and_fresh_breakers_count_only_fresh(): void
    {
        Event::fake([PlatformAlertTriggered::class]);

        $team = Team::factory()->create();
        $stale = Agent::factory()->create(['team_id' => $team->id]);
        $fresh = Agent::factory()->create(['team_id' => $team->id]);

        CircuitBreakerState::create([
            'team_id' => $team->id,
            'agent_id' => $stale->id,
            'state' => 'open',
            'failure_count' => 6,
            'last_failure_at' => now()->subDays(10),
            'opened_at' => now()->subDays(10),
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
        ]);

        CircuitBreakerState::create([
            'team_id' => $team->id,
            'agent_id' => $fresh->id,
            'state' => 'open',
            'failure_count' => 6,
            'last_failure_at' => now()->subMinutes(2),
            'opened_at' => now()->subMinutes(2),
            'cooldown_seconds' => 60,
            'failure_threshold' => 5,
        ]);

        app(AlertEvaluator::class)->evaluate();

        Event::assertDispatched(
            PlatformAlertTriggered::class,
            fn (PlatformAlertTriggered $event) => $event->rule->metricName === 'circuit_breaker_open'
                && (int) $event->currentValue === 1,
        );
    }
}
