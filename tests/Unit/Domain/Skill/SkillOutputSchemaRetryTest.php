<?php

namespace Tests\Unit\Domain\Skill;

use App\Domain\Skill\Actions\ExecuteSkillAction;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * Unit-level lockdown of the retry decision helpers on ExecuteSkillAction
 * (Sprint 14, mirror of Sprint 12's Agent test).
 */
class SkillOutputSchemaRetryTest extends TestCase
{
    use RefreshDatabase;

    private ExecuteSkillAction $action;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T',
            'slug' => 't-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $this->team->id]);
        $this->action = app(ExecuteSkillAction::class);
    }

    private function makeSkill(SkillType $type, ?int $retries = null): Skill
    {
        return Skill::factory()->create([
            'team_id' => $this->team->id,
            'type' => $type->value,
            'output_schema_max_retries' => $retries,
        ]);
    }

    private function invoke(string $method, array $args): mixed
    {
        $r = new ReflectionClass($this->action);
        $m = $r->getMethod($method);
        $m->setAccessible(true);

        return $m->invoke($this->action, ...$args);
    }

    public function test_llm_skills_support_retry(): void
    {
        $this->assertTrue($this->invoke('skillSupportsLlmRetry', [$this->makeSkill(SkillType::Llm)]));
    }

    public function test_hybrid_skills_support_retry(): void
    {
        $this->assertTrue($this->invoke('skillSupportsLlmRetry', [$this->makeSkill(SkillType::Hybrid)]));
    }

    public function test_guardrail_skills_support_retry(): void
    {
        $this->assertTrue($this->invoke('skillSupportsLlmRetry', [$this->makeSkill(SkillType::Guardrail)]));
    }

    public function test_connector_skills_do_not_retry(): void
    {
        $this->assertFalse($this->invoke('skillSupportsLlmRetry', [$this->makeSkill(SkillType::Connector)]));
    }

    public function test_rule_skills_do_not_retry(): void
    {
        $this->assertFalse($this->invoke('skillSupportsLlmRetry', [$this->makeSkill(SkillType::Rule)]));
    }

    public function test_resolveMaxRetries_falls_back_to_shared_config_key(): void
    {
        config(['agent.output_schema.max_retries_default' => 3]);
        $skill = $this->makeSkill(SkillType::Llm, null);
        $this->assertSame(3, $this->invoke('resolveMaxRetries', [$skill]));
    }

    public function test_resolveMaxRetries_per_skill_override_wins(): void
    {
        $skill = $this->makeSkill(SkillType::Llm, 1);
        $this->assertSame(1, $this->invoke('resolveMaxRetries', [$skill]));
    }

    public function test_resolveMaxRetries_clamps_to_range(): void
    {
        $this->assertSame(0, $this->invoke('resolveMaxRetries', [$this->makeSkill(SkillType::Llm, -1)]));
        $this->assertSame(5, $this->invoke('resolveMaxRetries', [$this->makeSkill(SkillType::Llm, 999)]));
    }

    public function test_zero_retries_preserves_validate_only_behavior(): void
    {
        $skill = $this->makeSkill(SkillType::Llm, 0);
        $this->assertSame(0, $this->invoke('resolveMaxRetries', [$skill]));
    }

    public function test_skill_output_schema_max_retries_is_fillable_and_cast(): void
    {
        $skill = $this->makeSkill(SkillType::Llm);
        $skill->update(['output_schema_max_retries' => 3]);
        $fresh = $skill->fresh();
        $this->assertSame(3, $fresh->output_schema_max_retries);
        $this->assertIsInt($fresh->output_schema_max_retries);
    }
}
