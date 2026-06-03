<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class AgentCharterTest extends TestCase
{
    use RefreshDatabase;

    private const FULL_CHARTER = [
        'owns' => ['Backend API design', 'Database schema'],
        'refuses' => ['Frontend styling', 'Marketing copy'],
        'escalate_to' => 'the Lead',
        'escalate_when' => ['A change touches authentication'],
    ];

    private function buildPrompt(Agent $agent): string
    {
        $method = new ReflectionMethod(ExecuteAgentAction::class, 'buildAgentSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke(app(ExecuteAgentAction::class), $agent, null, [], []);
    }

    public function test_charter_is_fillable_and_cast_to_array(): void
    {
        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'charter' => self::FULL_CHARTER,
        ]);

        $fresh = $agent->fresh();
        $this->assertIsArray($fresh->charter);
        $this->assertSame(['Backend API design', 'Database schema'], $fresh->charter['owns']);
        $this->assertSame('the Lead', $fresh->charter['escalate_to']);
    }

    public function test_no_charter_block_when_flag_disabled(): void
    {
        config(['agent.charter.enabled' => false]);
        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'charter' => self::FULL_CHARTER,
            'system_prompt_template' => null,
        ]);

        $this->assertStringNotContainsString('## Charter', $this->buildPrompt($agent));
    }

    public function test_no_charter_block_when_charter_null_even_if_flag_enabled(): void
    {
        config(['agent.charter.enabled' => true]);
        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'charter' => null,
            'system_prompt_template' => null,
        ]);

        $this->assertStringNotContainsString('## Charter', $this->buildPrompt($agent));
    }

    public function test_full_charter_renders_all_sections_when_enabled(): void
    {
        config(['agent.charter.enabled' => true]);
        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'charter' => self::FULL_CHARTER,
            'system_prompt_template' => null,
        ]);

        $prompt = $this->buildPrompt($agent);

        $this->assertStringContainsString('## Charter', $prompt);
        $this->assertStringContainsString('**I own:**', $prompt);
        $this->assertStringContainsString('Backend API design', $prompt);
        $this->assertStringContainsString('**I do NOT handle**', $prompt);
        $this->assertStringContainsString('Frontend styling', $prompt);
        $this->assertStringContainsString('I escalate to the Lead', $prompt);
        $this->assertStringContainsString('A change touches authentication', $prompt);
    }

    public function test_partial_charter_renders_only_present_sections(): void
    {
        config(['agent.charter.enabled' => true]);
        $team = Team::factory()->create();
        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'charter' => ['owns' => ['Backend API design']],
            'system_prompt_template' => null,
        ]);

        $prompt = $this->buildPrompt($agent);

        $this->assertStringContainsString('## Charter', $prompt);
        $this->assertStringContainsString('**I own:**', $prompt);
        $this->assertStringNotContainsString('**I do NOT handle**', $prompt);
        $this->assertStringNotContainsString('I escalate', $prompt);
    }
}
