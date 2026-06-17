<?php

namespace Tests\Feature\Mcp;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Shared\Models\Team;
use App\Mcp\Tools\Agent\AgentUpdateTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class AgentUpdateEvalGateToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Gate Team',
            'slug' => 'gate-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($user);
        app()->instance('mcp.team_id', $this->team->id);
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

    private function agent(): Agent
    {
        return Agent::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'backstory' => 'original',
            'config' => ['eval_gate_dataset_id' => 'ds-1'],
        ]);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    public function test_change_is_held_when_gate_fails(): void
    {
        config(['agent.eval_gate.enabled' => true, 'agent.eval_gate.threshold' => 7.0]);
        $this->bindReplayScore(3.0);
        $agent = $this->agent();

        $payload = $this->decode((new AgentUpdateTool)->handle(new Request([
            'agent_id' => $agent->id,
            'backstory' => 'regressed prompt',
        ])));

        $this->assertFalse($payload['success']);
        $this->assertTrue($payload['held']);

        // The change must NOT have been applied.
        $this->assertSame('original', $agent->fresh()->backstory);
    }

    public function test_change_applies_when_gate_passes(): void
    {
        config(['agent.eval_gate.enabled' => true, 'agent.eval_gate.threshold' => 7.0]);
        $this->bindReplayScore(9.0);
        $agent = $this->agent();

        $payload = $this->decode((new AgentUpdateTool)->handle(new Request([
            'agent_id' => $agent->id,
            'backstory' => 'improved prompt',
        ])));

        $this->assertTrue($payload['success']);
        $this->assertSame('improved prompt', $agent->fresh()->backstory);
        $this->assertTrue($payload['eval_gate']['passed']);
    }

    public function test_no_gate_when_flag_disabled(): void
    {
        config(['agent.eval_gate.enabled' => false]);
        $agent = $this->agent();

        $payload = $this->decode((new AgentUpdateTool)->handle(new Request([
            'agent_id' => $agent->id,
            'backstory' => 'freely changed',
        ])));

        $this->assertTrue($payload['success']);
        $this->assertSame('freely changed', $agent->fresh()->backstory);
    }
}
