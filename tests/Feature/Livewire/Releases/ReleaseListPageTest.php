<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Releases;

use App\Domain\Release\Models\Release;
use App\Domain\Shared\Models\Team;
use App\Livewire\Releases\ReleaseListPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ReleaseListPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Release List Test',
            'slug' => 'release-list-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    public function test_it_lists_team_releases_only(): void
    {
        Release::factory()->for($this->team)->create([
            'user_id' => $this->user->id,
            'name' => 'My Release',
        ]);

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-list-test',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        Release::factory()->for($otherTeam)->create([
            'user_id' => $otherUser->id,
            'name' => 'Hidden Release',
        ]);

        Livewire::test(ReleaseListPage::class)
            ->assertSee('My Release')
            ->assertDontSee('Hidden Release');
    }

    public function test_it_creates_release_via_form(): void
    {
        Livewire::test(ReleaseListPage::class)
            ->call('startCreate')
            ->set('newName', 'Inline Created Release')
            ->set('newVersion', 'v0.1')
            ->set('newNotes', 'first cut')
            ->call('create');

        $this->assertDatabaseHas('releases', [
            'team_id' => $this->team->id,
            'name' => 'Inline Created Release',
            'version' => 'v0.1',
        ]);
    }

    public function test_it_surfaces_duplicate_error(): void
    {
        Release::factory()->for($this->team)->create([
            'user_id' => $this->user->id,
            'name' => 'Duplicate Test',
            'slug' => 'duplicate-test',
            'version' => 'v1.0',
        ]);

        Livewire::test(ReleaseListPage::class)
            ->call('startCreate')
            ->set('newName', 'Duplicate Test')
            ->set('newVersion', 'v1.0')
            ->call('create')
            ->assertHasErrors(['newName']);
    }
}
