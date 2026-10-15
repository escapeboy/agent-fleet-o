<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Enums\ToolRecoveryTier;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\ToolRecoveryOrchestrator;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use ReflectionClass;
use Tests\TestCase;

/**
 * When tool recovery gives up (tier 6, GraceDegrade) the execution is stored as
 * "completed" with a canned notice. It must carry a marker so callers such as the
 * OpenAI-compatible endpoint can report the run as failed.
 */
class ExecuteAgentToolRecoveryDegradedTest extends TestCase
{
    use RefreshDatabase;

    private Agent $agent;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $team = Team::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $team->id]);
        $this->agent = Agent::factory()->create([
            'team_id' => $team->id,
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-4-5',
        ]);
    }

    private function runWithRecoveryResult(AiResponseDTO $response, ?ToolRecoveryTier $tier, bool $isPartial): array
    {
        $orchestrator = Mockery::mock(ToolRecoveryOrchestrator::class);
        $orchestrator->shouldReceive('attempt')->once()->andReturn([$response, $tier, $isPartial]);
        $this->app->instance(ToolRecoveryOrchestrator::class, $orchestrator);

        $action = app(ExecuteAgentAction::class);
        $method = (new ReflectionClass($action))->getMethod('executeWithTools');

        return $method->invoke($action, $this->agent, ['task' => 'do it'], [], $this->agent->team_id, $this->user->id, null);
    }

    private function response(string $content, int $toolCalls): AiResponseDTO
    {
        return new AiResponseDTO(
            content: $content,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 0, completionTokens: 0, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-sonnet-4-5',
            latencyMs: 0,
            toolCallsCount: $toolCalls,
            stepsCount: $toolCalls,
        );
    }

    public function test_grace_degrade_marks_execution_as_degraded(): void
    {
        $result = $this->runWithRecoveryResult(
            $this->response('Task could not be completed due to tool failures. Partial context was gathered.', 0),
            ToolRecoveryTier::GraceDegrade,
            true,
        );

        $this->assertSame('tool_recovery_exhausted', $result['execution']->quality_details['degraded'] ?? null);
    }

    public function test_recovered_run_is_not_marked_degraded(): void
    {
        $result = $this->runWithRecoveryResult($this->response('Here is the answer.', 2), ToolRecoveryTier::Retry, false);

        $this->assertSame('completed', $result['execution']->status);
        $this->assertArrayNotHasKey('degraded', $result['execution']->quality_details);
    }
}
