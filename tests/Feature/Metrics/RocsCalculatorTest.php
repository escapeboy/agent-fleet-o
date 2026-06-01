<?php

namespace Tests\Feature\Metrics;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Models\Metric;
use App\Domain\Metrics\Services\RocsCalculator;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class RocsCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private RocsCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = app(RocsCalculator::class);
    }

    public function test_joins_spend_and_value_into_roi(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        $this->makeRun($team, $experiment, null, 1000);
        $this->makeRun($team, $experiment, null, 2000);
        $this->makeValue($team, $experiment, 'payment', 5000); // $50.00

        $report = $this->calculator->forTeam($team->id, now()->subMonth());

        $this->assertSame(3000, $report['summary']['spend_credits']);
        $this->assertSame(3.0, $report['summary']['spend_usd']);
        $this->assertSame(50.0, $report['summary']['value_usd']);
        $this->assertSame(47.0, $report['summary']['net_usd']);
        $this->assertSame(16.67, $report['summary']['roi']);

        $row = $report['by_experiment'][0];
        $this->assertSame($experiment->id, $row['experiment_id']);
        $this->assertSame(3.0, $row['spend_usd']);
        $this->assertSame(50.0, $row['value_usd']);
        $this->assertSame(16.67, $row['roi']);
    }

    public function test_business_value_metric_counts_as_value(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        $this->makeRun($team, $experiment, null, 1000);
        $this->makeValue($team, $experiment, 'payment', 1000);        // $10
        $this->makeValue($team, $experiment, 'business_value', 2000);  // $20

        $report = $this->calculator->forTeam($team->id, now()->subMonth());

        $this->assertSame(30.0, $report['summary']['value_usd']);
    }

    public function test_non_value_metric_is_ignored(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        $this->makeRun($team, $experiment, null, 1000);
        $this->makeValue($team, $experiment, 'open_rate', 9999);

        $report = $this->calculator->forTeam($team->id, now()->subMonth());

        $this->assertSame(0.0, $report['summary']['value_usd']);
    }

    public function test_zero_spend_yields_null_roi(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        $this->makeValue($team, $experiment, 'payment', 5000);

        $report = $this->calculator->forTeam($team->id, now()->subMonth());

        $this->assertNull($report['summary']['roi']);
        $this->assertSame(0.0, $report['summary']['spend_usd']);
        $this->assertSame(50.0, $report['summary']['value_usd']);
    }

    public function test_per_agent_value_is_spend_proportional(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);
        $agentA = Agent::factory()->create(['team_id' => $team->id]);
        $agentB = Agent::factory()->create(['team_id' => $team->id]);

        $this->makeRun($team, $experiment, $agentA, 750);
        $this->makeRun($team, $experiment, $agentB, 250);
        $this->makeValue($team, $experiment, 'payment', 10000); // $100

        $report = $this->calculator->forTeam($team->id, now()->subMonth());

        $byAgent = collect($report['by_agent'])->keyBy('agent_id');
        $this->assertSame(75.0, $byAgent[$agentA->id]['attributed_value_usd']);
        $this->assertSame(25.0, $byAgent[$agentB->id]['attributed_value_usd']);
    }

    public function test_other_team_data_is_excluded(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);
        $otherExperiment = Experiment::factory()->create(['team_id' => $other->id]);

        $this->makeRun($team, $experiment, null, 1000);
        $this->makeValue($team, $experiment, 'payment', 5000);
        $this->makeRun($other, $otherExperiment, null, 9999);
        $this->makeValue($other, $otherExperiment, 'payment', 99999);

        $report = $this->calculator->forTeam($team->id, now()->subMonth());

        $this->assertSame(1000, $report['summary']['spend_credits']);
        $this->assertSame(50.0, $report['summary']['value_usd']);
        $this->assertCount(1, $report['by_experiment']);
    }

    public function test_data_before_window_is_excluded(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        $oldRun = $this->makeRun($team, $experiment, null, 5000);
        $oldRun->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();
        $this->makeValue($team, $experiment, 'payment', 3000, now()->subDays(40));

        $report = $this->calculator->forTeam($team->id, now()->subDays(30));

        $this->assertSame(0, $report['summary']['spend_credits']);
        $this->assertSame(0.0, $report['summary']['value_usd']);
    }

    private function makeRun(Team $team, ?Experiment $experiment, ?Agent $agent, int $credits): AiRun
    {
        return AiRun::create([
            'team_id' => $team->id,
            'experiment_id' => $experiment?->id,
            'agent_id' => $agent?->id,
            'purpose' => 'run',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'prompt_snapshot' => [],
            'cost_credits' => $credits,
            'status' => 'success',
        ]);
    }

    private function makeValue(Team $team, Experiment $experiment, string $type, float $cents, ?Carbon $occurredAt = null): Metric
    {
        return Metric::create([
            'team_id' => $team->id,
            'experiment_id' => $experiment->id,
            'type' => $type,
            'value' => $cents,
            'source' => 'test',
            'occurred_at' => $occurredAt ?? now(),
            'recorded_at' => now(),
        ]);
    }
}
