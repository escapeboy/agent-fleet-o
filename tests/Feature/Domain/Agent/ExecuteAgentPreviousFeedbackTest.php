<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Budget\Enums\LedgerType;
use App\Domain\Budget\Models\CreditLedger;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * QA feedback from a previous crew task attempt must reach an agent without
 * tools (direct prompt path) in the user prompt, and must not change the
 * system prompt, so a resumed Claude Code session keeps a stable hash.
 */
class ExecuteAgentPreviousFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    /** @var array<int, AiRequestDTO> */
    private array $requests = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['agent.bash_sandbox_mode' => 'php']);

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Feedback Team',
            'slug' => 'feedback-team-'.uniqid(),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        CreditLedger::withoutGlobalScopes()->create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'type' => LedgerType::Purchase,
            'amount' => 100000,
            'balance_after' => 100000,
            'description' => 'Test credit',
        ]);

        $gateway = Mockery::mock(AiGatewayInterface::class);
        $gateway->shouldReceive('complete')->andReturnUsing(function (AiRequestDTO $request) {
            $this->requests[] = $request;

            return new AiResponseDTO(
                content: 'done',
                parsedOutput: null,
                usage: new AiUsageDTO(promptTokens: 10, completionTokens: 10, costCredits: 1),
                provider: 'test',
                model: 'test-model',
                latencyMs: 10,
            );
        })->byDefault();
        $this->app->instance(AiGatewayInterface::class, $gateway);
    }

    public function test_previous_feedback_goes_into_user_prompt_not_system_prompt(): void
    {
        $agent = Agent::factory()->create(['team_id' => $this->team->id]);

        $this->runAgent($agent, ['task' => 'Write the summary']);
        $this->runAgent($agent, [
            'task' => 'Write the summary',
            'previous_feedback' => ['issues' => ['Missing the conclusion paragraph']],
            'retry_instructions' => 'This is retry attempt #2. Please address the feedback from the previous attempt.',
        ]);

        $workflowRequests = array_values(array_filter(
            $this->requests,
            fn (AiRequestDTO $r) => $r->purpose === 'agent.workflow_step',
        ));
        $this->assertCount(2, $workflowRequests);
        [$first, $retry] = $workflowRequests;

        $this->assertStringContainsString('## Feedback from previous attempt', $retry->userPrompt);
        $this->assertStringContainsString('Missing the conclusion paragraph', $retry->userPrompt);
        $this->assertStringContainsString('This is retry attempt #2', $retry->userPrompt);
        $this->assertStringNotContainsString('Feedback from previous attempt', $first->userPrompt);

        $this->assertStringNotContainsString('Missing the conclusion paragraph', $retry->systemPrompt);
        $this->assertSame($first->systemPrompt, $retry->systemPrompt);
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function runAgent(Agent $agent, array $input): void
    {
        $result = app(ExecuteAgentAction::class)->execute(
            agent: $agent,
            input: $input,
            teamId: $this->team->id,
            userId: $this->user->id,
        );

        $this->assertSame('completed', $result['execution']->status);
    }
}
