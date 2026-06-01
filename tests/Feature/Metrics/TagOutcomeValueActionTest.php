<?php

namespace Tests\Feature\Metrics;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Actions\TagOutcomeValueAction;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TagOutcomeValueActionTest extends TestCase
{
    use RefreshDatabase;

    private TagOutcomeValueAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->action = app(TagOutcomeValueAction::class);
    }

    public function test_tags_business_value_metric_in_cents(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        $metric = $this->action->execute(
            experimentId: $experiment->id,
            valueUsd: 50.0,
            teamId: $team->id,
            outcome: 'success',
            note: 'Closed the deal',
        );

        $this->assertNotNull($metric);
        $this->assertSame('business_value', $metric->type);
        $this->assertSame(5000.0, (float) $metric->value);
        $this->assertSame($team->id, $metric->team_id);
        $this->assertSame('success', $metric->metadata['outcome']);
        $this->assertSame('Closed the deal', $metric->metadata['note']);

        $this->assertDatabaseHas('metrics', [
            'experiment_id' => $experiment->id,
            'type' => 'business_value',
        ]);
    }

    public function test_rejects_experiment_from_another_team(): void
    {
        $team = Team::factory()->create();
        $other = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $other->id]);

        $metric = $this->action->execute(
            experimentId: $experiment->id,
            valueUsd: 10.0,
            teamId: $team->id,
        );

        $this->assertNull($metric);
        $this->assertDatabaseMissing('metrics', ['experiment_id' => $experiment->id]);
    }

    public function test_invalid_outcome_is_dropped(): void
    {
        $team = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $team->id]);

        $metric = $this->action->execute(
            experimentId: $experiment->id,
            valueUsd: 5.0,
            teamId: $team->id,
            outcome: 'banana',
        );

        $this->assertNotNull($metric);
        $this->assertArrayNotHasKey('outcome', $metric->metadata);
    }
}
