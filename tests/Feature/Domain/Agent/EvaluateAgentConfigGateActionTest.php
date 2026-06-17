<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\EvaluateAgentConfigGateAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EvaluateAgentConfigGateActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    private function agentWithDataset(): Agent
    {
        return Agent::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'config' => ['eval_gate_dataset_id' => 'dataset-1'],
        ]);
    }

    private function bindReplayScore(float $score): void
    {
        $fake = new class($score) extends ReplayEvaluationDatasetAction
        {
            public function __construct(private float $score) {}

            public function execute(
                string $teamId,
                string $datasetId,
                string $targetProvider,
                string $targetModel,
                ?string $systemPrompt = null,
                array $criteria = ['correctness', 'relevance'],
                ?string $judgeModel = null,
                int $maxCases = 100,
            ): EvaluationRun {
                $run = new EvaluationRun;
                $run->forceFill(['id' => 'run-test', 'summary' => ['overall_avg_score' => $this->score]]);

                return $run;
            }
        };

        app()->instance(ReplayEvaluationDatasetAction::class, $fake);
    }

    private function gate(): EvaluateAgentConfigGateAction
    {
        return app(EvaluateAgentConfigGateAction::class);
    }

    public function test_passthrough_when_flag_disabled(): void
    {
        config(['agent.eval_gate.enabled' => false]);
        $result = $this->gate()->execute($this->agentWithDataset(), ['goal' => 'new goal']);

        $this->assertFalse($result['gated']);
        $this->assertTrue($result['passed']);
    }

    public function test_passthrough_when_no_dataset_configured(): void
    {
        config(['agent.eval_gate.enabled' => true]);
        $agent = Agent::factory()->create(['team_id' => $this->team->id, 'config' => []]);

        $result = $this->gate()->execute($agent, ['goal' => 'new goal']);
        $this->assertFalse($result['gated']);
        $this->assertTrue($result['passed']);
    }

    public function test_passes_when_score_at_or_above_threshold(): void
    {
        config(['agent.eval_gate.enabled' => true, 'agent.eval_gate.threshold' => 7.0]);
        $this->bindReplayScore(9.0);

        $result = $this->gate()->execute($this->agentWithDataset(), ['backstory' => 'sharper prompt']);

        $this->assertTrue($result['gated']);
        $this->assertTrue($result['passed']);
        $this->assertSame(9.0, $result['score']);
        $this->assertSame('run-test', $result['run_id']);
    }

    public function test_holds_when_score_below_threshold(): void
    {
        config(['agent.eval_gate.enabled' => true, 'agent.eval_gate.threshold' => 7.0]);
        $this->bindReplayScore(4.0);

        $result = $this->gate()->execute($this->agentWithDataset(), ['backstory' => 'worse prompt']);

        $this->assertTrue($result['gated']);
        $this->assertFalse($result['passed']);
        $this->assertSame(4.0, $result['score']);
    }

    public function test_dataset_from_candidate_config_overrides_agent(): void
    {
        config(['agent.eval_gate.enabled' => true, 'agent.eval_gate.threshold' => 7.0]);
        $this->bindReplayScore(8.0);
        $agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'config' => [],
        ]);

        // No dataset on the agent, but the candidate change attaches one — the
        // gate must run against the candidate's dataset.
        $result = $this->gate()->execute($agent, ['config' => ['eval_gate_dataset_id' => 'ds-new']]);
        $this->assertTrue($result['gated']);
        $this->assertTrue($result['passed']);
    }

    public function test_fail_open_when_replay_throws(): void
    {
        config(['agent.eval_gate.enabled' => true]);
        $thrower = new class extends ReplayEvaluationDatasetAction
        {
            public function __construct() {}

            public function execute(
                string $teamId,
                string $datasetId,
                string $targetProvider,
                string $targetModel,
                ?string $systemPrompt = null,
                array $criteria = ['correctness', 'relevance'],
                ?string $judgeModel = null,
                int $maxCases = 100,
            ): EvaluationRun {
                throw new \RuntimeException('judge offline');
            }
        };
        app()->instance(ReplayEvaluationDatasetAction::class, $thrower);

        $result = $this->gate()->execute($this->agentWithDataset(), ['goal' => 'x']);
        // Fail-open: infra error must not block the edit.
        $this->assertFalse($result['gated']);
        $this->assertTrue($result['passed']);
    }
}
