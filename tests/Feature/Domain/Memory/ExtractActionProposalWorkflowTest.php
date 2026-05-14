<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Memory\Actions\ExtractFailureLessonAction;
use App\Domain\Memory\Actions\ExtractSuccessPatternAction;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Verifies that ExtractSuccessPattern / ExtractFailureLesson branch correctly
 * on the `memory.proposal_workflow.extractors_enabled` flag.
 *
 *   flag OFF (default) → writes to Successes / Failures (current behavior)
 *   flag ON            → writes to Proposed with metadata.target_tier set
 */
class ExtractActionProposalWorkflowTest extends TestCase
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
            'title' => 'Test experiment',
        ]);
    }

    public function test_success_extractor_writes_to_successes_when_flag_off(): void
    {
        config(['memory.proposal_workflow.extractors_enabled' => false]);

        $capturedArgs = null;
        $store = $this->captureStoreMemoryArgs($capturedArgs);

        $action = new ExtractSuccessPatternAction(
            $this->fakeGateway(json_encode([
                'pattern' => 'Use the right tool for the job',
                'key_technique' => 'tool_selection',
                'confidence' => 0.9,
                'tags' => ['tooling'],
            ])),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);

        $this->assertNotNull($capturedArgs, 'StoreMemoryAction::execute was not called');
        $this->assertSame(MemoryTier::Successes, $this->findTier($capturedArgs));

        $metadata = $this->findMetadata($capturedArgs);
        $this->assertArrayNotHasKey('target_tier', $metadata);
    }

    public function test_success_extractor_writes_to_proposed_when_flag_on(): void
    {
        config(['memory.proposal_workflow.extractors_enabled' => true]);

        $capturedArgs = null;
        $store = $this->captureStoreMemoryArgs($capturedArgs);

        $action = new ExtractSuccessPatternAction(
            $this->fakeGateway(json_encode([
                'pattern' => 'Use the right tool for the job',
                'key_technique' => 'tool_selection',
                'confidence' => 0.9,
                'tags' => ['tooling'],
            ])),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);

        $this->assertNotNull($capturedArgs);
        $this->assertSame(MemoryTier::Proposed, $this->findTier($capturedArgs));

        $metadata = $this->findMetadata($capturedArgs);
        $this->assertSame('successes', $metadata['target_tier'] ?? null);
    }

    public function test_failure_extractor_writes_to_failures_when_flag_off(): void
    {
        config(['memory.proposal_workflow.extractors_enabled' => false]);

        $capturedArgs = null;
        $store = $this->captureStoreMemoryArgs($capturedArgs);

        $action = new ExtractFailureLessonAction(
            $this->fakeGateway(json_encode([
                'lesson' => 'Tool timed out — retry with smaller payload',
                'root_cause' => 'tool_timeout',
                'confidence' => 0.9,
                'tags' => ['constraint'],
            ])),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);

        $this->assertNotNull($capturedArgs);
        $this->assertSame(MemoryTier::Failures, $this->findTier($capturedArgs));
    }

    public function test_failure_extractor_writes_to_proposed_when_flag_on(): void
    {
        config(['memory.proposal_workflow.extractors_enabled' => true]);

        $capturedArgs = null;
        $store = $this->captureStoreMemoryArgs($capturedArgs);

        $action = new ExtractFailureLessonAction(
            $this->fakeGateway(json_encode([
                'lesson' => 'Tool timed out — retry with smaller payload',
                'root_cause' => 'tool_timeout',
                'confidence' => 0.9,
                'tags' => ['constraint'],
            ])),
            $store,
        );
        $action->execute($this->experiment->id, $this->team->id);

        $this->assertNotNull($capturedArgs);
        $this->assertSame(MemoryTier::Proposed, $this->findTier($capturedArgs));

        $metadata = $this->findMetadata($capturedArgs);
        $this->assertSame('failures', $metadata['target_tier'] ?? null);
    }

    /**
     * Build a StoreMemoryAction mock that captures the args of the single
     * expected execute() call into the supplied reference.
     */
    private function captureStoreMemoryArgs(&$capturedArgs): StoreMemoryAction
    {
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')
            ->once()
            ->withArgs(function (...$args) use (&$capturedArgs) {
                $capturedArgs = $args;

                return true;
            })
            ->andReturn([]);

        return $store;
    }

    private function findTier(array $args): ?MemoryTier
    {
        foreach ($args as $arg) {
            if ($arg instanceof MemoryTier) {
                return $arg;
            }
        }

        return null;
    }

    /**
     * Locate the metadata array passed to StoreMemoryAction::execute. The
     * extractors stamp `extracted_at` into every metadata payload, so we use
     * that key as a uniqueness probe.
     */
    private function findMetadata(array $args): array
    {
        foreach ($args as $arg) {
            if (is_array($arg) && array_key_exists('extracted_at', $arg)) {
                return $arg;
            }
        }

        return [];
    }

    private function fakeGateway(string $responseJson): AiGatewayInterface
    {
        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturn(
            new AiResponseDTO(
                content: $responseJson,
                parsedOutput: null,
                usage: new AiUsageDTO(
                    promptTokens: 100,
                    completionTokens: 50,
                    costCredits: 0,
                ),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 10,
            ),
        );

        return $gateway;
    }
}
