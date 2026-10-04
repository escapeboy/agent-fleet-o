<?php

namespace Tests\Feature\Livewire\Skills;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillVersion;
use App\Livewire\Skills\SkillPlayground;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class SkillPlaygroundRenderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The input placeholder shows the literal "{{variable}}" syntax. Unescaped,
     * Blade compiled it to PHP and every render threw
     * `Undefined constant "variable"` (Sentry fleetq #1208).
     */
    public function test_the_playground_renders_the_literal_placeholder_syntax(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);

        $skill = Skill::factory()->create(['team_id' => $team->id, 'type' => SkillType::Llm->value]);
        $version = SkillVersion::create([
            'skill_id' => $skill->id,
            'version' => '1.0.0',
            'input_schema' => [],
            'output_schema' => [],
            'configuration' => [],
            'created_by' => $user->id,
        ]);

        $this->actingAs($user);

        Livewire::test(SkillPlayground::class, ['skillId' => $skill->id, 'versionId' => $version->id])
            ->assertOk()
            ->assertSee('Use {{variable}} syntax', false);
    }
}
