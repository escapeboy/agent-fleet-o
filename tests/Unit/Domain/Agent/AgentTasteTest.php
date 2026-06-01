<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Services\AgentPromptCompiler;
use App\Domain\Memory\Services\MemoryNudgeInjector;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AgentTasteTest extends TestCase
{
    use RefreshDatabase;

    private function compiler(): AgentPromptCompiler
    {
        return new AgentPromptCompiler(new class extends MemoryNudgeInjector
        {
            public function __construct() {}

            public function nudgeFor(Agent $agent): ?string
            {
                return null;
            }
        });
    }

    public function test_taste_variable_substituted_in_template(): void
    {
        $agent = Agent::factory()->make([
            'taste' => 'Prefer minimal, calm interfaces.',
            'system_prompt_template' => ['context_injection' => 'Taste: {{agent.taste}}'],
        ]);

        $compiled = $this->compiler()->compile($agent);

        $this->assertStringContainsString('Taste: Prefer minimal, calm interfaces.', $compiled);
    }

    public function test_taste_persists(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->create(['team_id' => $team->id, 'taste' => 'Concise and direct.']);

        $this->assertSame('Concise and direct.', $agent->fresh()->taste);
    }

    public function test_build_system_prompt_includes_taste_section(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'taste' => 'Favor boring, proven solutions over clever ones.',
            'system_prompt_template' => null,
        ]);

        $method = new ReflectionMethod(ExecuteAgentAction::class, 'buildAgentSystemPrompt');
        $method->setAccessible(true);
        $prompt = $method->invoke(app(ExecuteAgentAction::class), $agent, null, [], []);

        $this->assertStringContainsString('## Taste & Judgment', $prompt);
        $this->assertStringContainsString('Favor boring, proven solutions', $prompt);
    }

    public function test_no_taste_section_when_absent(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'taste' => null,
            'system_prompt_template' => null,
        ]);

        $method = new ReflectionMethod(ExecuteAgentAction::class, 'buildAgentSystemPrompt');
        $method->setAccessible(true);
        $prompt = $method->invoke(app(ExecuteAgentAction::class), $agent, null, [], []);

        $this->assertStringNotContainsString('## Taste & Judgment', $prompt);
    }
}
