<?php

namespace Tests\Feature\Livewire\Skills;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Models\Skill;
use App\Livewire\Skills\ImportSkillForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class ImportSkillFormTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Form Team',
            'slug' => 'form-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_renders(): void
    {
        Livewire::test(ImportSkillForm::class)->assertOk();
    }

    public function test_imports_valid_skill_md_and_redirects(): void
    {
        $md = "---\nname: form-imported\ndescription: Imported through the form.\n---\n\n# Body\n\nDo work.";

        Livewire::test(ImportSkillForm::class)
            ->set('skillMd', $md)
            ->call('import')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('skills', [
            'team_id' => $this->team->id,
            'description' => 'Imported through the form.',
        ]);
    }

    public function test_invalid_skill_md_surfaces_error_and_creates_nothing(): void
    {
        Livewire::test(ImportSkillForm::class)
            ->set('skillMd', 'not a skill md document')
            ->call('import')
            ->assertHasErrors('skillMd');

        $this->assertSame(0, Skill::withoutGlobalScopes()->where('team_id', $this->team->id)->count());
    }
}
