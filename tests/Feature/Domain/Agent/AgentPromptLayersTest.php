<?php

namespace Tests\Feature\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentPromptCompiler;
use App\Domain\Memory\Services\MemoryNudgeInjector;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Characterization tests for the agent system-prompt layer stack, backing
 * docs/architecture/architecture-agent-prompt-layers.md.
 *
 * Two of these pin CURRENT behaviour that the doc records as a gap. If someone
 * fixes the gap, the test fails — that is the intent. Update the doc in the
 * same change rather than deleting the assertion.
 */
class AgentPromptLayersTest extends TestCase
{
    use RefreshDatabase;

    private function bindNudge(?string $nudge): void
    {
        $this->app->instance(MemoryNudgeInjector::class, new class($nudge) extends MemoryNudgeInjector
        {
            public function __construct(private readonly ?string $nudge) {}

            public function nudgeFor($agent): ?string
            {
                return $this->nudge;
            }
        });
        $this->app->forgetInstance(AgentPromptCompiler::class);
        $this->app->forgetInstance(ExecuteAgentAction::class);
    }

    private function buildPrompt(Agent $agent): string
    {
        $method = new ReflectionMethod(ExecuteAgentAction::class, 'buildAgentSystemPrompt');
        $method->setAccessible(true);

        return $method->invoke(app(ExecuteAgentAction::class), $agent, null, [], [], []);
    }

    public function test_role_goal_and_backstory_land_in_the_prompt(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create([
            'name' => 'Scout',
            'role' => 'Researcher',
            'goal' => 'Find prior art',
            'backstory' => 'Ten years in patent search',
            'system_prompt_template' => null,
        ]);
        $this->bindNudge(null);

        $prompt = $this->buildPrompt($agent->fresh());

        $this->assertStringContainsString('named "Scout"', $prompt);
        $this->assertStringContainsString('Your role: Researcher', $prompt);
        $this->assertStringContainsString('Your goal: Find prior art', $prompt);
        $this->assertStringContainsString('Background: Ten years in patent search', $prompt);
    }

    public function test_the_template_replaces_backstory_when_present(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create([
            'backstory' => 'RAW BACKSTORY',
            'system_prompt_template' => [
                'personality' => 'Terse.',
                'rules' => ['Never guess'],
            ],
        ]);
        $this->bindNudge(null);

        $prompt = $this->buildPrompt($agent->fresh());

        $this->assertStringContainsString('## Personality', $prompt);
        $this->assertStringContainsString('Never guess', $prompt);
        $this->assertStringNotContainsString('RAW BACKSTORY', $prompt);
    }

    public function test_gap_memory_nudge_is_dropped_when_no_template_is_set(): void
    {
        // AgentPromptCompiler::compile() appends the nudge to the backstory, but
        // buildAgentSystemPrompt only uses the compiled string when
        // system_prompt_template is non-empty. With no template it falls to the
        // raw backstory branch and the nudge never reaches the model.
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create([
            'backstory' => 'Some background',
            'system_prompt_template' => null,
        ]);
        $this->bindNudge('SAVE YOUR LEARNINGS');

        // The compiler itself does produce the nudge...
        $compiled = app(AgentPromptCompiler::class)->compile($agent->fresh());
        $this->assertStringContainsString('SAVE YOUR LEARNINGS', $compiled);

        // ...but the assembled execution prompt drops it.
        $prompt = $this->buildPrompt($agent->fresh());
        $this->assertStringNotContainsString('SAVE YOUR LEARNINGS', $prompt);
    }

    public function test_gap_template_variables_have_no_runtime_values_during_execution(): void
    {
        // ExecuteAgentAction calls compile($agent) with no $runtimeContext, so
        // {{recent_memories}} and {{available_tools}} always resolve to their
        // placeholder defaults no matter what the agent actually has.
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create([
            'system_prompt_template' => [
                'context_injection' => 'Memories: {{recent_memories}} | Tools: {{available_tools}}',
            ],
        ]);
        $this->bindNudge(null);

        $prompt = $this->buildPrompt($agent->fresh());

        $this->assertStringContainsString('Memories: No recent memories available.', $prompt);
        $this->assertStringContainsString('Tools: Standard tools.', $prompt);
    }

    public function test_agent_scoped_variables_do_resolve(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->for($team)->create([
            'name' => 'Vega',
            'role' => 'Auditor',
            'system_prompt_template' => ['personality' => 'I am {{agent.name}}, the {{agent.role}}.'],
        ]);
        $this->bindNudge(null);

        $prompt = $this->buildPrompt($agent->fresh());

        $this->assertStringContainsString('I am Vega, the Auditor.', $prompt);
    }
}
