<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\PlaybookStep;
use App\Domain\Memory\Actions\UnifiedMemorySearchAction;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Executors\LlmNodeExecutor;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Prism\Prism\Embeddings\Response;
use Prism\Prism\Facades\Prism;
use Prism\Prism\ValueObjects\Embedding;
use Prism\Prism\ValueObjects\EmbeddingsUsage;
use Prism\Prism\ValueObjects\Meta;
use Tests\TestCase;

class LlmNodeOperationsTest extends TestCase
{
    use RefreshDatabase;

    private AiGatewayInterface $gateway;

    private UnifiedMemorySearchAction $memorySearch;

    private Team $team;

    private Experiment $experiment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->gateway = Mockery::mock(AiGatewayInterface::class);
        $this->memorySearch = Mockery::mock(UnifiedMemorySearchAction::class);

        $this->app->instance(AiGatewayInterface::class, $this->gateway);
        $this->app->instance(UnifiedMemorySearchAction::class, $this->memorySearch);

        $this->team = Team::factory()->create();

        $workflow = Workflow::factory()->create(['team_id' => $this->team->id]);

        $this->experiment = Experiment::factory()->create([
            'team_id' => $this->team->id,
            'workflow_id' => $workflow->id,
        ]);
    }

    // ── text_complete (default) ───────────────────────────────────────────────

    public function test_text_complete_returns_text_and_token_count(): void
    {
        $this->gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->fakeResponse('Hello world', null, promptTokens: 10, completionTokens: 5));

        $result = $this->executeNode([
            'operation' => 'text_complete',
            'prompt_template' => 'Say hello.',
        ]);

        $this->assertSame('Hello world', $result['text']);
        $this->assertSame(15, $result['tokens_used']);
    }

    public function test_text_complete_is_default_when_operation_omitted(): void
    {
        $this->gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->fakeResponse('Default op'));

        $result = $this->executeNode(['prompt_template' => 'Go.']);

        $this->assertArrayHasKey('text', $result);
        $this->assertSame('Default op', $result['text']);
    }

    public function test_text_complete_interpolates_system_prompt(): void
    {
        $captured = null;
        $this->gateway->shouldReceive('complete')
            ->once()
            ->withArgs(function ($dto) use (&$captured) {
                $captured = $dto;

                return true;
            })
            ->andReturn($this->fakeResponse('ok'));

        $step = $this->makeStepWithOutput([]);
        $this->executeNode(
            ['system_prompt' => 'ID: {{experiment.id}}', 'prompt_template' => 'Go.'],
            $step,
        );

        $this->assertStringContainsString($this->experiment->id, $captured->systemPrompt);
    }

    // ── extract ───────────────────────────────────────────────────────────────

    public function test_extract_returns_structured_data(): void
    {
        $this->gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->fakeResponse('', ['sentiment' => 'positive', 'score' => 0.9]));

        $result = $this->executeNode([
            'operation' => 'extract',
            'prompt_template' => 'Analyse: {{context}}',
            'output_schema' => [
                'sentiment' => ['type' => 'string', 'description' => 'Sentiment label'],
                'score' => ['type' => 'number', 'description' => 'Confidence score'],
            ],
        ]);

        $this->assertSame(['sentiment' => 'positive', 'score' => 0.9], $result['extracted']);
        $this->assertSame('positive', $result['sentiment']);
        $this->assertSame(0.9, $result['score']);
    }

    public function test_extract_returns_empty_on_null_structured(): void
    {
        $this->gateway->shouldReceive('complete')
            ->once()
            ->andReturn($this->fakeResponse('', null));

        $result = $this->executeNode([
            'operation' => 'extract',
            'output_schema' => ['name' => ['type' => 'string']],
        ]);

        $this->assertSame([], $result['extracted']);
    }

    // ── embed ─────────────────────────────────────────────────────────────────

    /**
     * Embed calls Prism::embeddings() directly; tested via integration only unless
     * Prism::fake() is available in the installed version.
     */
    public function test_embed_operation_config_keys_pass_through(): void
    {
        if (! method_exists(Prism::class, 'fake')) {
            $this->markTestSkipped('Prism::fake() not available in this version.');
        }

        $fakeVector = array_fill(0, 8, 0.1);
        Prism::fake([
            new Response(
                embeddings: [new Embedding($fakeVector)],
                usage: new EmbeddingsUsage(tokens: 5),
                meta: new Meta(id: 'test', model: 'text-embedding-3-small'),
            ),
        ]);

        $result = $this->executeNode([
            'operation' => 'embed',
            'text_template' => 'hello world',
            'embed_provider' => 'openai',
            'embed_model' => 'text-embedding-3-small',
        ]);

        $this->assertArrayHasKey('vector', $result);
        $this->assertSame('openai/text-embedding-3-small', $result['model']);
        $this->assertSame('hello world', $result['input_text']);
        $this->assertSame(8, $result['dimensions']);
    }

    // ── search ────────────────────────────────────────────────────────────────

    public function test_search_returns_memory_results(): void
    {
        $fakeResults = Collection::make([
            ['type' => 'vector', 'content' => 'Fact A', 'score' => 0.95, 'metadata' => []],
            ['type' => 'kg', 'content' => 'Fact B', 'score' => 0.82, 'metadata' => []],
        ]);

        $this->memorySearch->shouldReceive('execute')
            ->once()
            ->with($this->team->id, 'what is the capital', null, null, 3)
            ->andReturn($fakeResults);

        $result = $this->executeNode([
            'operation' => 'search',
            'query_template' => 'what is the capital',
            'search_k' => 3,
        ]);

        $this->assertSame('what is the capital', $result['query']);
        $this->assertSame(2, $result['result_count']);
        $this->assertCount(2, $result['results']);
        $this->assertSame('Fact A', $result['results'][0]['content']);
    }

    public function test_search_defaults_to_k5(): void
    {
        $this->memorySearch->shouldReceive('execute')
            ->once()
            ->withArgs(fn ($tid, $q, $agentId, $projectId, $k) => $k === 5)
            ->andReturn(Collection::make([]));

        $result = $this->executeNode([
            'operation' => 'search',
            'query_template' => 'anything',
        ]);

        $this->assertSame(0, $result['result_count']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param array<string, mixed> $config */
    private function executeNode(array $config, ?PlaybookStep $step = null): array
    {
        $node = WorkflowNode::create([
            'workflow_id' => $this->experiment->workflow_id,
            'type' => WorkflowNodeType::Llm,
            'label' => 'LLM Node',
            'order' => 0,
            'config' => $config,
        ]);

        $step ??= $this->makeStepWithOutput([]);

        return app(LlmNodeExecutor::class)->execute($node, $step, $this->experiment);
    }

    /** @param array<string, mixed> $output */
    private function makeStepWithOutput(array $output): PlaybookStep
    {
        return PlaybookStep::create([
            'experiment_id' => $this->experiment->id,
            'order' => 0,
            'output' => $output,
        ]);
    }

    /** @param array<string, mixed>|null $parsedOutput */
    private function fakeResponse(
        string $content,
        ?array $parsedOutput = null,
        int $promptTokens = 0,
        int $completionTokens = 0,
    ): AiResponseDTO {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: $parsedOutput,
            usage: new AiUsageDTO(
                promptTokens: $promptTokens,
                completionTokens: $completionTokens,
                costCredits: 0,
            ),
            provider: 'anthropic',
            model: 'claude-haiku-4-5',
            latencyMs: 0,
        );
    }
}
