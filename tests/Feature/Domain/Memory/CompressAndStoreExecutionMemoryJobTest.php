<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentExecution;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Jobs\CompressAndStoreExecutionMemoryJob;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class CompressAndStoreExecutionMemoryJobTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($user, ['role' => 'owner']);

        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    private function makeExecution(string $status, mixed $output): AgentExecution
    {
        return AgentExecution::create([
            'agent_id' => $this->agent->id,
            'team_id' => $this->team->id,
            'status' => $status,
            'input' => ['task' => 'test'],
            'output' => $output,
            'cost_credits' => 10,
            'duration_ms' => 500,
        ]);
    }

    private function makeJob(AgentExecution $execution): CompressAndStoreExecutionMemoryJob
    {
        return new CompressAndStoreExecutionMemoryJob($execution->id);
    }

    public function test_skips_non_completed_execution(): void
    {
        $execution = $this->makeExecution('failed', ['result' => 'something']);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $this->makeJob($execution)->handle(
            $gateway,
            $store,
            app(RetrieveRelevantMemoriesAction::class),
            app(ProviderResolver::class),
        );
    }

    public function test_skips_execution_with_empty_output(): void
    {
        $execution = $this->makeExecution('completed', null);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldNotReceive('complete');

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldNotReceive('execute');

        $this->makeJob($execution)->handle(
            $gateway,
            $store,
            app(RetrieveRelevantMemoriesAction::class),
            app(ProviderResolver::class),
        );
    }

    public function test_stores_compressed_memory_on_successful_completion(): void
    {
        $execution = $this->makeExecution('completed', ['result' => 'Task completed. Found 3 issues.']);

        $compressed = 'Agent found 3 critical issues and completed the task successfully.';

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andReturn(
            new AiResponseDTO(
                content: $compressed,
                parsedOutput: null,
                usage: new AiUsageDTO(10, 20, 1),
                provider: 'anthropic',
                model: 'claude-haiku-4-5',
                latencyMs: 200,
            ),
        );

        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')
            ->once()
            ->withArgs(function ($teamId, $agentId, $content, $sourceType, $projectId, $sourceId, $metadata) use ($compressed) {
                return $content === $compressed
                    && $sourceType === 'execution'
                    && ($metadata['auto_captured'] ?? false) === true
                    && ($metadata['compressed'] ?? false) === true;
            })
            ->andReturn([]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')->once()->andReturn([]);

        $this->makeJob($execution)->handle($gateway, $store, $retrieve, app(ProviderResolver::class));
    }

    public function test_falls_back_to_raw_output_when_compression_fails(): void
    {
        $rawOutput = 'Raw execution output text.';
        $execution = $this->makeExecution('completed', ['result' => $rawOutput]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->once()->andThrow(new \RuntimeException('Gateway unavailable'));

        $capturedContent = null;
        $store = Mockery::mock(StoreMemoryAction::class);
        $store->shouldReceive('execute')
            ->once()
            ->withArgs(function ($teamId, $agentId, $content) use (&$capturedContent) {
                $capturedContent = $content;

                return true;
            })
            ->andReturn([]);

        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')->once()->andReturn([]);

        $this->makeJob($execution)->handle($gateway, $store, $retrieve, app(ProviderResolver::class));

        $this->assertNotNull($capturedContent);
        $this->assertStringContainsString($rawOutput, $capturedContent);
    }
}
