<?php

namespace Tests\Feature\Domain\Crew;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Actions\ValidateTaskOutputAction;
use App\Domain\Crew\Enums\CrewExecutionStatus;
use App\Domain\Crew\Enums\CrewTaskStatus;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewExecution;
use App\Domain\Crew\Models\CrewTaskExecution;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ValidateTaskOutputUnverifiedTest extends TestCase
{
    use RefreshDatabase;

    private function makeExecutionWithTask(): array
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Unverified Test Team',
            'slug' => 'unverified-test',
            'owner_id' => $user->id,
            'settings' => [],
        ]);

        $qaAgent = Agent::factory()->create([
            'team_id' => $team->id,
            'name' => 'Quality Reviewer',
            'role' => 'Quality Reviewer',
            'goal' => 'Validate accuracy and quality',
        ]);

        $crew = Crew::factory()->create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'coordinator_agent_id' => $qaAgent->id,
            'qa_agent_id' => $qaAgent->id,
        ]);

        $execution = CrewExecution::create([
            'team_id' => $team->id,
            'crew_id' => $crew->id,
            'goal' => 'Ship the feature',
            'status' => CrewExecutionStatus::Executing,
            'config_snapshot' => [
                'qa_agent' => ['id' => $qaAgent->id],
                'quality_threshold' => 0.70,
            ],
            'total_cost_credits' => 0,
        ]);

        $task = CrewTaskExecution::create([
            'crew_execution_id' => $execution->id,
            'agent_id' => $qaAgent->id,
            'title' => 'Implement deploy sync',
            'description' => 'Sync env vars on deploy',
            'status' => CrewTaskStatus::Running,
            'input_context' => ['expected_output' => 'Working deploy'],
            'output' => ['result' => 'done'],
            'attempt_number' => 1,
            'max_attempts' => 3,
        ]);

        return [$execution, $task];
    }

    private function makeAction(string $responseContent): ValidateTaskOutputAction
    {
        $gateway = $this->createMock(AiGatewayInterface::class);
        $gateway->method('complete')->willReturn(new AiResponseDTO(
            content: $responseContent,
            parsedOutput: null,
            usage: new AiUsageDTO(promptTokens: 50, completionTokens: 80, costCredits: 0),
            provider: 'anthropic',
            model: 'claude-haiku-4-5-20251001',
            latencyMs: 100,
        ));

        $providerResolver = $this->createMock(ProviderResolver::class);
        $providerResolver->method('resolve')->willReturn([
            'provider' => 'anthropic',
            'model' => 'claude-haiku-4-5-20251001',
        ]);

        return new ValidateTaskOutputAction($gateway, $providerResolver);
    }

    public function test_unverified_coverage_gap_is_parsed_and_persisted(): void
    {
        [$execution, $task] = $this->makeExecutionWithTask();

        $json = json_encode([
            'passed' => true,
            'score' => 0.85,
            'feedback' => 'Looks good.',
            'issues' => [],
            'unverified' => ['Could not run the deploy against a live host', 'No sandbox secret to confirm env sync'],
            'criterion_scores' => ['accuracy' => 0.9],
        ]);

        $result = $this->makeAction($json)->execute($task, $execution);

        $this->assertSame(
            ['Could not run the deploy against a live host', 'No sandbox secret to confirm env sync'],
            $result['unverified'],
        );

        $task->refresh();
        $this->assertSame(
            ['Could not run the deploy against a live host', 'No sandbox secret to confirm env sync'],
            $task->qa_feedback['unverified'],
        );
    }

    public function test_unverified_defaults_to_empty_array_when_omitted(): void
    {
        [$execution, $task] = $this->makeExecutionWithTask();

        $json = json_encode([
            'passed' => true,
            'score' => 0.9,
            'feedback' => 'All checks performed.',
            'issues' => [],
            'criterion_scores' => [],
        ]);

        $result = $this->makeAction($json)->execute($task, $execution);

        $this->assertSame([], $result['unverified']);
        $task->refresh();
        $this->assertSame([], $task->qa_feedback['unverified']);
    }
}
