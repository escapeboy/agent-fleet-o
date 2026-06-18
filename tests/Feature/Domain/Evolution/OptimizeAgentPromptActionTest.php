<?php

namespace Tests\Feature\Domain\Evolution;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evaluation\Actions\ReplayEvaluationDatasetAction;
use App\Domain\Evaluation\Models\EvaluationRun;
use App\Domain\Evolution\Actions\OptimizeAgentPromptAction;
use App\Domain\Evolution\Enums\EvolutionType;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OptimizeAgentPromptActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
    }

    private function agent(array $overrides = []): Agent
    {
        return Agent::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'role' => 'analyst',
            'goal' => 'answer questions',
            'backstory' => 'original backstory',
            'config' => ['eval_gate_dataset_id' => 'ds-1'],
        ], $overrides));
    }

    /** Gateway that returns a fixed set of candidate variants as JSON. */
    private function gatewayWithVariants(array $variants): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(new AiResponseDTO(
            content: json_encode(['variants' => $variants]),
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 1, completionTokens: 1, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 1,
        ));

        return $gateway;
    }

    /** Replay double that returns the given scores in call order (baseline first). */
    private function replayWithScores(float ...$scores): ReplayEvaluationDatasetAction
    {
        return new class(...$scores) extends ReplayEvaluationDatasetAction
        {
            /** @var list<float> */
            private array $scores;

            private int $i = 0;

            public function __construct(float ...$scores)
            {
                $this->scores = $scores;
            }

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
                $score = $this->scores[$this->i] ?? end($this->scores);
                $this->i++;
                $run = new EvaluationRun;
                $run->forceFill(['id' => 'run-'.$this->i, 'summary' => ['overall_avg_score' => $score]]);

                return $run;
            }
        };
    }

    public function test_disabled_returns_status_disabled(): void
    {
        config(['agent.prompt_optimizer.enabled' => false]);
        $action = new OptimizeAgentPromptAction(Mockery::mock(AiGatewayInterface::class), $this->replayWithScores(5.0));

        $result = $action->execute($this->agent());
        $this->assertSame('disabled', $result['status']);
    }

    public function test_no_dataset_returns_status_no_dataset(): void
    {
        config(['agent.prompt_optimizer.enabled' => true]);
        $action = new OptimizeAgentPromptAction(Mockery::mock(AiGatewayInterface::class), $this->replayWithScores(5.0));

        $result = $action->execute($this->agent(['config' => []]));
        $this->assertSame('no_dataset', $result['status']);
    }

    public function test_proposes_best_variant_that_beats_baseline(): void
    {
        config(['agent.prompt_optimizer.enabled' => true, 'agent.prompt_optimizer.min_improvement' => 0.0]);

        $gateway = $this->gatewayWithVariants([
            ['goal' => 'sharper goal', 'backstory' => 'better A', 'strategy' => 'sharpen_goal', 'reasoning' => 'r1'],
            ['goal' => 'answer questions', 'backstory' => 'better B', 'strategy' => 'add_examples', 'reasoning' => 'r2'],
        ]);
        // baseline 5.0, candidate1 8.0 (winner), candidate2 6.0
        $action = new OptimizeAgentPromptAction($gateway, $this->replayWithScores(5.0, 8.0, 6.0));
        $agent = $this->agent();

        $result = $action->execute($agent, 2);

        $this->assertSame('proposed', $result['status']);
        $this->assertSame(5.0, $result['baseline_score']);
        $this->assertSame(8.0, $result['best_score']);

        $proposal = EvolutionProposal::find($result['proposal_id']);
        $this->assertNotNull($proposal);
        $this->assertSame($agent->id, $proposal->agent_id);
        $this->assertSame(EvolutionType::AgentConfig, $proposal->evolution_type);
        $this->assertSame('better A', $proposal->proposed_changes['backstory']);
        $this->assertSame('sharper goal', $proposal->proposed_changes['goal']);
        // JSONB normalises 8.0 → 8 on round-trip, so compare loosely.
        $this->assertEquals(8.0, $proposal->mutation_variant['candidate_score']);
        $this->assertEquals(5.0, $proposal->mutation_variant['parent_score']);
    }

    public function test_no_improvement_creates_no_proposal(): void
    {
        config(['agent.prompt_optimizer.enabled' => true, 'agent.prompt_optimizer.min_improvement' => 0.0]);

        $gateway = $this->gatewayWithVariants([
            ['backstory' => 'worse A', 'strategy' => 'simplify', 'reasoning' => 'r1'],
            ['backstory' => 'worse B', 'strategy' => 'simplify', 'reasoning' => 'r2'],
        ]);
        // baseline 7.0, candidates 6.0 and 5.0 — none beat baseline
        $action = new OptimizeAgentPromptAction($gateway, $this->replayWithScores(7.0, 6.0, 5.0));

        $result = $action->execute($this->agent(), 2);

        $this->assertSame('no_improvement', $result['status']);
        $this->assertArrayNotHasKey('proposal_id', $result);
        $this->assertSame(0, EvolutionProposal::where('team_id', $this->team->id)->count());
    }

    public function test_min_improvement_margin_is_respected(): void
    {
        // Candidate beats baseline by only 0.2, but margin requires 0.5 → no proposal.
        config(['agent.prompt_optimizer.enabled' => true, 'agent.prompt_optimizer.min_improvement' => 0.5]);

        $gateway = $this->gatewayWithVariants([
            ['backstory' => 'marginally better', 'strategy' => 'simplify', 'reasoning' => 'r'],
        ]);
        $action = new OptimizeAgentPromptAction($gateway, $this->replayWithScores(7.0, 7.2));

        $result = $action->execute($this->agent(), 1);
        $this->assertSame('no_improvement', $result['status']);
    }
}
