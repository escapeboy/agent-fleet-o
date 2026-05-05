<?php

namespace Tests\Feature\Domain\Experiment;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Experiment\DTOs\DoneVerdict;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Services\DoneConditionJudge;
use App\Domain\Experiment\States\TransitionPrerequisiteValidator;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class DoneConditionGateTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_gate_disabled_passes_through(): void
    {
        $experiment = $this->makeExperiment(doneGateEnabled: false);

        $error = app(TransitionPrerequisiteValidator::class)
            ->validate($experiment, ExperimentStatus::Completed);

        $this->assertNull($error);
    }

    public function test_no_features_means_no_judge_invocation(): void
    {
        $experiment = $this->makeExperiment(doneGateEnabled: true);

        // No agent_execution → no feature list → gate is a no-op
        $judgeMock = Mockery::mock(DoneConditionJudge::class);
        $judgeMock->shouldNotReceive('evaluate');
        $this->app->instance(DoneConditionJudge::class, $judgeMock);

        $error = app(TransitionPrerequisiteValidator::class)
            ->validate($experiment, ExperimentStatus::Completed);

        $this->assertNull($error);
    }

    public function test_judge_confirms_passes(): void
    {
        $experiment = $this->makeExperiment(doneGateEnabled: true);
        $this->seedFeatureList($experiment);

        $judgeMock = Mockery::mock(DoneConditionJudge::class);
        $judgeMock->shouldReceive('evaluate')
            ->once()
            ->andReturn(new DoneVerdict(
                confirmed: true,
                reasoning: 'all criteria met',
                missing: [],
                nextActions: [],
                judgeModel: 'claude-haiku-4-5',
            ));
        $this->app->instance(DoneConditionJudge::class, $judgeMock);

        $validator = app(TransitionPrerequisiteValidator::class);
        $error = $validator->validate($experiment, ExperimentStatus::Completed);

        $this->assertNull($error);
        $this->assertNotNull($validator->lastJudgeVerdict);
        $this->assertTrue($validator->lastJudgeVerdict->confirmed);
    }

    public function test_judge_denies_blocks(): void
    {
        $experiment = $this->makeExperiment(doneGateEnabled: true);
        $this->seedFeatureList($experiment);

        $judgeMock = Mockery::mock(DoneConditionJudge::class);
        $judgeMock->shouldReceive('evaluate')->once()->andReturn(new DoneVerdict(
            confirmed: false,
            reasoning: 'tests not run',
            missing: ['test output', 'deploy URL'],
            nextActions: ['run phpunit', 'curl /health'],
            judgeModel: 'claude-haiku-4-5',
        ));
        $this->app->instance(DoneConditionJudge::class, $judgeMock);

        $validator = app(TransitionPrerequisiteValidator::class);
        $error = $validator->validate($experiment, ExperimentStatus::Completed);

        $this->assertNotNull($error);
        $this->assertStringContainsString('Done-Condition Gate denied', $error);
        $this->assertStringContainsString('tests not run', $error);
        $this->assertNotNull($validator->lastJudgeVerdict);
        $this->assertFalse($validator->lastJudgeVerdict->confirmed);
    }

    public function test_kill_switch_bypasses_gate(): void
    {
        $experiment = $this->makeExperiment(doneGateEnabled: true, killSwitch: true);
        $this->seedFeatureList($experiment);

        $judgeMock = Mockery::mock(DoneConditionJudge::class);
        $judgeMock->shouldNotReceive('evaluate');
        $this->app->instance(DoneConditionJudge::class, $judgeMock);

        $error = app(TransitionPrerequisiteValidator::class)
            ->validate($experiment, ExperimentStatus::Completed);

        $this->assertNull($error);
    }

    private function makeExperiment(bool $doneGateEnabled, bool $killSwitch = false): Experiment
    {
        $team = Team::factory()->create();

        return Experiment::factory()->for($team)->create([
            'status' => ExperimentStatus::Executing,
            'constraints' => [
                'done_gate_enabled' => $doneGateEnabled,
                'done_gate_kill_switch' => $killSwitch,
            ],
        ]);
    }

    private function seedFeatureList(Experiment $experiment): void
    {
        $agent = Agent::factory()->for($experiment->team)->create();
        $exec = new AgentExecution([
            'agent_id' => $agent->id,
            'team_id' => $experiment->team_id,
            'experiment_id' => $experiment->id,
            'status' => 'completed',
            'input' => ['placeholder' => true],
            'workspace_contract' => [
                'agents_md' => '',
                'feature_list_json' => json_encode([
                    'features' => [
                        ['id' => '1', 'title' => 'X', 'done_criteria' => 'tests pass', 'status' => 'pending'],
                    ],
                ]),
                'progress_md' => '',
                'init_sh' => '',
            ],
        ]);
        $exec->save();
    }
}
