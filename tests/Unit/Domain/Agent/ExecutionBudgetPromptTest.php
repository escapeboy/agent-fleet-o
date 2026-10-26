<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The tool loop limit (Prism withMaxSteps) counts model turns, and one turn can
 * carry several tool calls. Telling the model "N tool calls" made it stop a
 * 12-payment task after ~6 payments while the step limit still had room.
 */
class ExecutionBudgetPromptTest extends TestCase
{
    use RefreshDatabase;

    private function buildPrompt(int $maxSteps): string
    {
        $team = Team::factory()->create(['settings' => []]);
        $agent = Agent::factory()->create(['team_id' => $team->id]);
        $agent->setRelation('team', $team);

        $action = app(ExecuteAgentAction::class);
        $method = new ReflectionMethod($action, 'buildAgentSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($action, $agent, null, [], [], ['max_steps' => $maxSteps]);
    }

    public function test_budget_is_stated_in_steps_not_tool_calls(): void
    {
        $prompt = $this->buildPrompt(10);

        $this->assertStringContainsString('## Execution Budget', $prompt);
        $this->assertStringContainsString('You have a maximum of 10 steps for this task.', $prompt);
        $this->assertStringContainsString('several tool calls in a single step', $prompt);
        $this->assertStringContainsString('Plan to complete your core work by step 8.', $prompt);
        $this->assertStringContainsString('Reserve the final 2 step(s)', $prompt);
        $this->assertStringNotContainsString('tool calls for this task', $prompt);
    }

    public function test_budget_section_omitted_without_tool_loop(): void
    {
        $prompt = $this->buildPrompt(0);

        $this->assertStringNotContainsString('## Execution Budget', $prompt);
    }
}
