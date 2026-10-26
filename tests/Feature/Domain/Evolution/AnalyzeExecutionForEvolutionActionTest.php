<?php

namespace Tests\Feature\Domain\Evolution;

use App\Domain\Agent\Models\Agent;
use App\Domain\Evolution\Actions\AnalyzeExecutionForEvolutionAction;
use App\Domain\Evolution\Models\EvolutionProposal;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Verifies the KEEP_BASE signal: when the LLM determines no changes are needed,
 * execute() must return null without creating an EvolutionProposal.
 */
class AnalyzeExecutionForEvolutionActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create([
            'team_id' => $this->team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
            'role' => 'analyst',
            'goal' => 'answer questions accurately',
        ]);
    }

    public function test_returns_null_and_creates_no_proposal_on_keep_base(): void
    {
        $action = new AnalyzeExecutionForEvolutionAction(
            $this->fakeGateway(json_encode(['keep_base' => true, 'analysis' => 'Agent is performing well.'])),
        );

        $result = $action->execute($this->agent);

        $this->assertNull($result, 'KEEP_BASE must return null, not a proposal');
        $this->assertSame(0, EvolutionProposal::where('agent_id', $this->agent->id)->count());
    }

    public function test_creates_proposal_when_changes_are_suggested(): void
    {
        $action = new AnalyzeExecutionForEvolutionAction(
            $this->fakeGateway(json_encode([
                'analysis' => 'Goal is too vague',
                'changes' => ['goal' => 'Answer accounting questions with citations.'],
                'reasoning' => 'Specificity reduces hallucination.',
                'confidence' => 0.8,
            ])),
        );

        $result = $action->execute($this->agent);

        $this->assertNotNull($result);
        $this->assertInstanceOf(EvolutionProposal::class, $result);
        $this->assertSame($this->agent->id, $result->agent_id);
    }

    private function fakeGateway(string $responseJson): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(
            new AiResponseDTO(
                content: $responseJson,
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 100, completionTokens: 50, costCredits: 0),
                provider: 'anthropic',
                model: 'claude-sonnet-4-5',
                latencyMs: 10,
            ),
        );

        return $gateway;
    }
}
