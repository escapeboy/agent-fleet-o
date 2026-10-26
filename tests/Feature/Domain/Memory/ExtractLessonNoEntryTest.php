<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Actions\ExtractFailureLessonAction;
use App\Domain\Memory\Actions\ExtractSuccessPatternAction;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Verifies the NO_ENTRY signal: when the LLM cannot form a reliable lesson,
 * it returns {"NO_ENTRY": true} and nothing must be stored.
 */
class ExtractLessonNoEntryTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    private Experiment $experiment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $this->experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
        ]);
    }

    public function test_failure_extractor_does_not_store_on_no_entry(): void
    {
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $action = new ExtractFailureLessonAction(
            $this->fakeGateway(json_encode(['NO_ENTRY' => true])),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);
    }

    public function test_failure_extractor_does_not_store_on_no_entry_with_markdown_fence(): void
    {
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $action = new ExtractFailureLessonAction(
            $this->fakeGateway("```json\n{\"NO_ENTRY\": true}\n```"),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);
    }

    public function test_success_extractor_does_not_store_on_no_entry(): void
    {
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $action = new ExtractSuccessPatternAction(
            $this->fakeGateway(json_encode(['NO_ENTRY' => true])),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);
    }

    public function test_failure_extractor_stores_when_valid_cue_lesson_evidence(): void
    {
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')->once()->andReturn([]);

        $action = new ExtractFailureLessonAction(
            $this->fakeGateway(json_encode([
                'cue' => 'tool_timeout',
                'lesson' => 'Retry with smaller payload after a timeout.',
                'evidence' => 'Stage error: connection timed out at 30s.',
                'confidence' => 0.85,
            ])),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);
    }

    public function test_success_extractor_stores_when_valid_cue_pattern_evidence(): void
    {
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')->once()->andReturn([]);

        $action = new ExtractSuccessPatternAction(
            $this->fakeGateway(json_encode([
                'cue' => 'file-read task',
                'pattern' => 'Chain read + transform for large files.',
                'evidence' => 'Stage completed with quality_score 0.97.',
                'confidence' => 0.9,
            ])),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);
    }

    private function fakeGateway(string $responseJson): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(
            new AiResponseDTO(
                content: $responseJson,
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 50, completionTokens: 20, costCredits: 0),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 5,
            ),
        );

        return $gateway;
    }
}
