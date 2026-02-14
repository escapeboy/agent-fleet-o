<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

class TransitionExperimentActionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private TransitionExperimentAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake queue to prevent listeners from making real AI calls
        Queue::fake();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);

        // Seed budget so PauseOnBudgetExceeded listener doesn't auto-pause
        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 10000,
            'balance_after' => 10000,
            'description' => 'Seed balance',
            'metadata' => [],
        ]);

        $this->action = app(TransitionExperimentAction::class);
    }

    private function createExperiment(ExperimentStatus $status = ExperimentStatus::Draft): Experiment
    {
        return Experiment::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test Experiment',
            'status' => $status,
            'track' => 'growth',
            'execution_mode' => 'auto',
            'hypothesis' => 'Test hypothesis',
            'config' => [],
            'constraints' => [],
            'budget_cap_credits' => 0,
            'budget_spent_credits' => 0,
            'current_iteration' => 1,
            'max_iterations' => 10,
        ]);
    }

    public function test_can_transition_from_draft_to_scoring(): void
    {
        $experiment = $this->createExperiment(ExperimentStatus::Draft);

        $result = $this->action->execute(
            $experiment,
            ExperimentStatus::Scoring,
            reason: 'Starting scoring',
            actorId: $this->user->id,
        );

        $this->assertEquals(ExperimentStatus::Scoring, $result->status);
        $this->assertNotNull($result->started_at);
    }

    public function test_records_state_transition(): void
    {
        $experiment = $this->createExperiment(ExperimentStatus::Draft);

        $this->action->execute($experiment, ExperimentStatus::Scoring);

        $this->assertDatabaseHas('experiment_state_transitions', [
            'experiment_id' => $experiment->id,
            'from_state' => 'draft',
            'to_state' => 'scoring',
        ]);
    }

    public function test_rejects_invalid_transition(): void
    {
        $experiment = $this->createExperiment(ExperimentStatus::Draft);

        $this->expectException(InvalidArgumentException::class);

        $this->action->execute($experiment, ExperimentStatus::Completed);
    }

    public function test_sets_completed_at_on_completion(): void
    {
        $experiment = $this->createExperiment(ExperimentStatus::Executing);

        $result = $this->action->execute($experiment, ExperimentStatus::Completed);

        $this->assertEquals(ExperimentStatus::Completed, $result->status);
        $this->assertNotNull($result->completed_at);
    }

    public function test_sets_killed_at_on_kill(): void
    {
        $experiment = $this->createExperiment(ExperimentStatus::Scoring);

        $result = $this->action->execute($experiment, ExperimentStatus::Killed, reason: 'No longer needed');

        $this->assertEquals(ExperimentStatus::Killed, $result->status);
        $this->assertNotNull($result->killed_at);
    }

    public function test_cannot_transition_from_terminal_state(): void
    {
        $experiment = $this->createExperiment(ExperimentStatus::Completed);

        $this->expectException(InvalidArgumentException::class);

        $this->action->execute($experiment, ExperimentStatus::Scoring);
    }

    public function test_dispatches_experiment_transitioned_event(): void
    {
        $experiment = $this->createExperiment(ExperimentStatus::Draft);

        Event::fake();

        $this->action->execute($experiment, ExperimentStatus::Scoring);

        Event::assertDispatched(
            ExperimentTransitioned::class,
            function ($event) use ($experiment) {
                return $event->experiment->id === $experiment->id
                    && $event->fromState === ExperimentStatus::Draft
                    && $event->toState === ExperimentStatus::Scoring;
            },
        );
    }
}
