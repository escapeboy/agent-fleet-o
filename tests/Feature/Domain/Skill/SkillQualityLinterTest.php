<?php

namespace Tests\Feature\Domain\Skill;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\DTOs\SkillLintFinding;
use App\Domain\Skill\Enums\SkillLintMode;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Services\SkillQualityLinter;
use App\Domain\Tool\Models\Tool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillQualityLinterTest extends TestCase
{
    use RefreshDatabase;

    private function linter(): SkillQualityLinter
    {
        return app(SkillQualityLinter::class);
    }

    /**
     * @param  array<int, SkillLintFinding>  $findings
     */
    private function hasMode(array $findings, SkillLintMode $mode): bool
    {
        foreach ($findings as $f) {
            if ($f->mode === $mode) {
                return true;
            }
        }

        return false;
    }

    public function test_clean_llm_skill_has_no_findings(): void
    {
        $team = Team::factory()->create();
        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Llm,
            'system_prompt' => 'Summarize the user input in one sentence.',
            'output_schema' => ['type' => 'object', 'properties' => ['summary' => ['type' => 'string']]],
        ]);

        $this->assertSame([], $this->linter()->lint($skill));
    }

    public function test_flags_phantom_tooling(): void
    {
        $team = Team::factory()->create();
        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Llm,
            'system_prompt' => 'Always call `frobnicate()` before responding.',
            'output_schema' => ['type' => 'object'],
        ]);

        $findings = $this->linter()->lint($skill);
        $this->assertTrue($this->hasMode($findings, SkillLintMode::PhantomTooling));
    }

    public function test_resolvable_tool_reference_is_not_phantom(): void
    {
        $team = Team::factory()->create();
        Tool::factory()->create(['team_id' => $team->id, 'name' => 'frobnicate', 'slug' => 'frobnicate']);

        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Llm,
            'system_prompt' => 'Always call `frobnicate()` before responding.',
            'output_schema' => ['type' => 'object'],
        ]);

        $findings = $this->linter()->lint($skill);
        $this->assertFalse($this->hasMode($findings, SkillLintMode::PhantomTooling));
    }

    public function test_flags_reference_bloat(): void
    {
        config(['skills.lint.bloat_token_threshold' => 100]);
        $team = Team::factory()->create();
        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Llm,
            'system_prompt' => str_repeat('Follow the established procedure carefully. ', 200),
            'output_schema' => ['type' => 'object'],
        ]);

        $findings = $this->linter()->lint($skill);
        $this->assertTrue($this->hasMode($findings, SkillLintMode::ReferenceBloat));
    }

    public function test_flags_empty_guidance(): void
    {
        $team = Team::factory()->create();
        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Llm,
            'system_prompt' => '',
            'output_schema' => ['type' => 'object'],
        ]);

        $findings = $this->linter()->lint($skill);
        $this->assertTrue($this->hasMode($findings, SkillLintMode::EmptyGuidance));
    }

    public function test_flags_missing_output_schema(): void
    {
        $team = Team::factory()->create();
        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Llm,
            'system_prompt' => 'Summarize the input.',
            'output_schema' => [],
        ]);

        $findings = $this->linter()->lint($skill);
        $this->assertTrue($this->hasMode($findings, SkillLintMode::MissingOutputSchema));
    }

    public function test_non_llm_skill_only_considers_phantom_tooling(): void
    {
        config(['skills.lint.bloat_token_threshold' => 10]);
        $team = Team::factory()->create();
        $skill = Skill::factory()->create([
            'team_id' => $team->id,
            'type' => SkillType::Connector,
            'system_prompt' => str_repeat('lots of text here ', 200),
            'output_schema' => [],
        ]);

        $findings = $this->linter()->lint($skill);
        $this->assertFalse($this->hasMode($findings, SkillLintMode::ReferenceBloat));
        $this->assertFalse($this->hasMode($findings, SkillLintMode::MissingOutputSchema));
        $this->assertFalse($this->hasMode($findings, SkillLintMode::EmptyGuidance));
    }
}
