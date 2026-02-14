<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\Event;

class ExperimentControllerTest extends ApiTestCase
{
    private function createExperiment(array $overrides = []): Experiment
    {
        return Experiment::create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test Experiment',
            'thesis' => 'Testing hypothesis',
            'track' => 'growth',
            'status' => 'draft',
            'constraints' => [],
            'success_criteria' => [],
            'budget_cap_credits' => 10000,
            'budget_spent_credits' => 0,
            'max_iterations' => 10,
            'current_iteration' => 1,
            'max_outbound_count' => 100,
            'outbound_count' => 0,
        ], $overrides));
    }

    public function test_can_list_experiments(): void
    {
        $this->actingAsApiUser();
        $this->createExperiment(['title' => 'First']);
        $this->createExperiment(['title' => 'Second']);

        $response = $this->getJson('/api/v1/experiments');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'title', 'status', 'track']],
            ]);
    }

    public function test_can_filter_experiments_by_status(): void
    {
        $this->actingAsApiUser();
        $this->createExperiment(['title' => 'Draft', 'status' => 'draft']);
        $this->createExperiment(['title' => 'Completed', 'status' => 'completed']);

        $response = $this->getJson('/api/v1/experiments?filter[status]=draft');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Draft');
    }

    public function test_can_show_experiment(): void
    {
        $this->actingAsApiUser();
        $experiment = $this->createExperiment();

        $response = $this->getJson("/api/v1/experiments/{$experiment->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $experiment->id)
            ->assertJsonPath('data.title', 'Test Experiment');
    }

    public function test_can_create_experiment(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/experiments', [
            'title' => 'New Experiment',
            'thesis' => 'Will this work?',
            'track' => 'revenue',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'New Experiment')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('experiments', ['title' => 'New Experiment']);
    }

    public function test_create_experiment_validates_input(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/experiments', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title', 'thesis', 'track']);
    }

    public function test_can_transition_experiment(): void
    {
        // Fake events to prevent pipeline listeners from firing
        Event::fake([ExperimentTransitioned::class]);

        $this->actingAsApiUser();
        $experiment = $this->createExperiment(['status' => 'draft']);

        $response = $this->postJson("/api/v1/experiments/{$experiment->id}/transition", [
            'status' => 'scoring',
            'reason' => 'Starting scoring phase',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'scoring');

        Event::assertDispatched(ExperimentTransitioned::class);
    }

    public function test_unauthenticated_cannot_list_experiments(): void
    {
        $response = $this->getJson('/api/v1/experiments');

        $response->assertStatus(401);
    }
}
