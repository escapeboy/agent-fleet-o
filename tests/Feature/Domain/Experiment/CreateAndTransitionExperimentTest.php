<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CreateAndTransitionExperimentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        // Fake queue to prevent stage jobs from dispatching to AI
        Queue::fake();

        // Seed team credit balance so PauseOnBudgetExceeded doesn't auto-pause
        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 100000,
            'balance_after' => 100000,
            'description' => 'Test balance',
        ]);
    }

    public function test_create_experiment_with_defaults(): void
    {
        $action = app(CreateExperimentAction::class);

        $experiment = $action->execute(
            userId: $this->user->id,
            title: 'Test Experiment',
            thesis: 'Testing thesis',
            track: 'growth',
            teamId: $this->team->id,
        );

        $this->assertInstanceOf(Experiment::class, $experiment);
        $this->assertEquals('Test Experiment', $experiment->title);
        $this->assertEquals(ExperimentStatus::Draft, $experiment->status);
        $this->assertEquals(10000, $experiment->budget_cap_credits);
        $this->assertEquals(0, $experiment->budget_spent_credits);
        $this->assertEquals(3, $experiment->max_iterations);
        $this->assertEquals(1, $experiment->current_iteration);
    }

    public function test_create_experiment_with_custom_budget(): void
    {
        $action = app(CreateExperimentAction::class);

        $experiment = $action->execute(
            userId: $this->user->id,
            title: 'Custom Budget',
            thesis: 'Testing budget',
            track: 'growth',
            budgetCapCredits: 5000,
            maxIterations: 5,
            teamId: $this->team->id,
        );

        $this->assertEquals(5000, $experiment->budget_cap_credits);
        $this->assertEquals(5, $experiment->max_iterations);
    }

    public function test_transition_draft_to_scoring(): void
    {
        $createAction = app(CreateExperimentAction::class);
        $transitionAction = app(TransitionExperimentAction::class);

        $experiment = $createAction->execute(
            userId: $this->user->id,
            title: 'Transition Test',
            thesis: 'Testing transitions',
            track: 'growth',
            teamId: $this->team->id,
        );

        $result = $transitionAction->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Scoring,
            reason: 'Starting scoring',
            actorId: $this->user->id,
        );

        $this->assertEquals(ExperimentStatus::Scoring, $result->status);
        $this->assertNotNull($result->started_at);

        $this->assertDatabaseHas('experiment_state_transitions', [
            'experiment_id' => $experiment->id,
            'from_state' => 'draft',
            'to_state' => 'scoring',
        ]);
    }

    public function test_invalid_transition_throws_exception(): void
    {
        $createAction = app(CreateExperimentAction::class);
        $transitionAction = app(TransitionExperimentAction::class);

        $experiment = $createAction->execute(
            userId: $this->user->id,
            title: 'Invalid Transition',
            thesis: 'Testing invalid transitions',
            track: 'growth',
            teamId: $this->team->id,
        );

        $this->expectException(\InvalidArgumentException::class);

        $transitionAction->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Completed,
        );
    }

    public function test_kill_experiment(): void
    {
        $createAction = app(CreateExperimentAction::class);
        $transitionAction = app(TransitionExperimentAction::class);

        $experiment = $createAction->execute(
            userId: $this->user->id,
            title: 'Kill Test',
            thesis: 'Testing kill',
            track: 'growth',
            teamId: $this->team->id,
        );

        // Transition to scoring first
        $experiment = $transitionAction->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Scoring,
        );

        // Kill
        $result = $transitionAction->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Killed,
            reason: 'Cancelled by user',
            actorId: $this->user->id,
        );

        $this->assertEquals(ExperimentStatus::Killed, $result->status);
        $this->assertNotNull($result->killed_at);
    }

    public function test_pause_and_resume_experiment(): void
    {
        $createAction = app(CreateExperimentAction::class);
        $transitionAction = app(TransitionExperimentAction::class);

        $experiment = $createAction->execute(
            userId: $this->user->id,
            title: 'Pause Test',
            thesis: 'Testing pause/resume',
            track: 'growth',
            teamId: $this->team->id,
        );

        // Transition to scoring
        $experiment = $transitionAction->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Scoring,
        );

        // Pause
        $experiment = $transitionAction->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Paused,
            reason: 'Manual pause',
        );

        $this->assertEquals(ExperimentStatus::Paused, $experiment->status);

        // Resume back to scoring
        $experiment = $transitionAction->execute(
            experiment: $experiment,
            toState: ExperimentStatus::Scoring,
            reason: 'Resuming',
        );

        $this->assertEquals(ExperimentStatus::Scoring, $experiment->status);
    }
}
