<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Agent\Models\AiRun;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Metrics\Models\Metric;
use App\Domain\Shared\Models\Team;

class MetricsRocsTest extends ApiTestCase
{
    public function test_rocs_endpoint_returns_cost_vs_value_summary(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);

        AiRun::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'purpose' => 'run',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'prompt_snapshot' => [],
            'cost_credits' => 2500, // $2.50
            'status' => 'success',
        ]);
        Metric::create([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'type' => 'payment',
            'value' => 5025, // $50.25
            'source' => 'stripe',
            'occurred_at' => now(),
            'recorded_at' => now(),
        ]);

        $response = $this->actingAsApiUser()->getJson('/api/v1/metrics/rocs');

        $response->assertOk()
            ->assertJsonPath('data.summary.spend_usd', 2.5)
            ->assertJsonPath('data.summary.value_usd', 50.25)
            ->assertJsonPath('data.summary.roi', 20.1); // 50.25 / 2.50
    }

    public function test_rocs_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/metrics/rocs')->assertUnauthorized();
    }

    public function test_tag_value_endpoint_records_business_value(): void
    {
        $experiment = Experiment::factory()->create(['team_id' => $this->team->id]);

        $response = $this->actingAsApiUser()->postJson('/api/v1/metrics/tag-value', [
            'experiment_id' => $experiment->id,
            'value_usd' => 25,
            'outcome' => 'success',
            'note' => 'Booked a demo',
        ]);

        $response->assertCreated()->assertJsonPath('data.type', 'business_value');

        $this->assertDatabaseHas('metrics', [
            'experiment_id' => $experiment->id,
            'type' => 'business_value',
            'team_id' => $this->team->id,
        ]);
    }

    public function test_tag_value_rejects_cross_team_experiment(): void
    {
        $other = Team::factory()->create();
        $experiment = Experiment::factory()->create(['team_id' => $other->id]);

        $this->actingAsApiUser()
            ->postJson('/api/v1/metrics/tag-value', [
                'experiment_id' => $experiment->id,
                'value_usd' => 10,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('metrics', ['experiment_id' => $experiment->id]);
    }
}
