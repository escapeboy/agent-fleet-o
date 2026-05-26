<?php

namespace Tests\Feature\Skills;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SkillExportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_exports_skill_md_as_download(): void
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Export Team',
            'slug' => 'export-team',
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $user->update(['current_team_id' => $team->id]);
        $team->users()->attach($user, ['role' => 'owner']);

        $skill = Skill::factory()->for($team)->create([
            'name' => 'Downloadable',
            'slug' => 'downloadable',
            'system_prompt' => 'Instructions.',
        ]);

        $response = $this->actingAs($user)->get(route('skills.export', $skill));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/markdown; charset=UTF-8');
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('---', $response->getContent());
    }
}
