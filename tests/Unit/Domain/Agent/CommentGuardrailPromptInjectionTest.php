<?php

namespace Tests\Unit\Domain\Agent;

use App\Domain\Agent\Actions\ExecuteAgentAction;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class CommentGuardrailPromptInjectionTest extends TestCase
{
    use RefreshDatabase;

    private function buildPrompt(Agent $agent): string
    {
        $action = app(ExecuteAgentAction::class);
        $method = new ReflectionMethod($action, 'buildAgentSystemPrompt');
        $method->setAccessible(true);

        return (string) $method->invoke($action, $agent);
    }

    public function test_injects_guardrail_when_team_flag_enabled(): void
    {
        $team = Team::factory()->create(['settings' => ['enable_comment_guardrail' => true]]);
        $agent = Agent::factory()->create(['team_id' => $team->id]);
        $agent->setRelation('team', $team);

        $prompt = $this->buildPrompt($agent);

        $this->assertStringContainsString('code_inspect_comments', $prompt);
        $this->assertStringContainsString('Comment Discipline', $prompt);
    }

    public function test_omits_guardrail_when_flag_absent(): void
    {
        $team = Team::factory()->create(['settings' => []]);
        $agent = Agent::factory()->create(['team_id' => $team->id]);
        $agent->setRelation('team', $team);

        $prompt = $this->buildPrompt($agent);

        $this->assertStringNotContainsString('code_inspect_comments', $prompt);
    }
}
