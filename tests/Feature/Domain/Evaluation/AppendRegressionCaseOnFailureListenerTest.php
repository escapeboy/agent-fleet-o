<?php

namespace Tests\Feature\Domain\Evaluation;

use App\Domain\Evaluation\Jobs\AppendRegressionCaseJob;
use App\Domain\Evaluation\Listeners\AppendRegressionCaseOnFailureListener;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AppendRegressionCaseOnFailureListenerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create(['name' => 'L', 'slug' => 'l-'.uniqid(), 'owner_id' => $user->id, 'settings' => []]);
        config(['evaluation.auto_eval.enabled' => true]);
    }

    private function makeExperiment(): Experiment
    {
        return Experiment::factory()->create([
            'team_id' => $this->team->id,
            'thesis' => 'Ship the onboarding flow',
        ]);
    }

    public function test_dispatches_job_on_failed_transition(): void
    {
        Queue::fake();
        $experiment = $this->makeExperiment();

        (new AppendRegressionCaseOnFailureListener)->handle(new ExperimentTransitioned(
            $experiment, ExperimentStatus::Building, ExperimentStatus::BuildingFailed,
        ));

        Queue::assertPushed(AppendRegressionCaseJob::class, function (AppendRegressionCaseJob $job) {
            return $job->teamId === $this->team->id
                && $job->errorModeLabel === 'experiment:building_failed'
                && $job->input === 'Ship the onboarding flow';
        });
    }

    public function test_no_dispatch_on_non_failed_transition(): void
    {
        Queue::fake();
        $experiment = $this->makeExperiment();

        (new AppendRegressionCaseOnFailureListener)->handle(new ExperimentTransitioned(
            $experiment, ExperimentStatus::Building, ExperimentStatus::Completed,
        ));

        Queue::assertNotPushed(AppendRegressionCaseJob::class);
    }

    public function test_no_dispatch_when_flag_off(): void
    {
        config(['evaluation.auto_eval.enabled' => false]);
        Queue::fake();
        $experiment = $this->makeExperiment();

        (new AppendRegressionCaseOnFailureListener)->handle(new ExperimentTransitioned(
            $experiment, ExperimentStatus::Building, ExperimentStatus::BuildingFailed,
        ));

        Queue::assertNotPushed(AppendRegressionCaseJob::class);
    }
}
