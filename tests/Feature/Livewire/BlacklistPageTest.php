<?php

namespace Tests\Feature\Livewire;

use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Outbound\BlacklistPage;
use App\Models\Blacklist;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class BlacklistPageTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->team->users()->attach($this->user, ['role' => TeamRole::Owner->value]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->actingAs($this->user);
    }

    public function test_add_creates_a_blacklist_entry(): void
    {
        Livewire::test(BlacklistPage::class)
            ->set('type', 'email')
            ->set('value', 'Spam@Example.com')
            ->set('reason', 'known spammer')
            ->call('add')
            ->assertHasNoErrors();

        $entry = Blacklist::query()->where('type', 'email')->first();
        $this->assertNotNull($entry);
        // Value is normalized to lowercase.
        $this->assertSame('spam@example.com', $entry->value);
        $this->assertSame($this->team->id, $entry->team_id);
        $this->assertSame($this->user->id, $entry->added_by);
    }

    public function test_remove_deletes_an_entry(): void
    {
        $entry = Blacklist::create([
            'team_id' => $this->team->id,
            'type' => 'domain',
            'value' => 'example.com',
            'added_by' => $this->user->id,
        ]);

        Livewire::test(BlacklistPage::class)
            ->call('remove', $entry->id)
            ->assertHasNoErrors();

        $this->assertSame(0, Blacklist::query()->count());
    }

    public function test_list_only_shows_current_team_entries(): void
    {
        Blacklist::create([
            'team_id' => $this->team->id,
            'type' => 'email',
            'value' => 'mine@example.com',
            'added_by' => $this->user->id,
        ]);

        $otherUser = User::factory()->create();
        $otherTeam = Team::factory()->create(['owner_id' => $otherUser->id]);
        Blacklist::withoutGlobalScopes()->create([
            'team_id' => $otherTeam->id,
            'type' => 'email',
            'value' => 'theirs@example.com',
            'added_by' => $otherUser->id,
        ]);

        Livewire::test(BlacklistPage::class)
            ->assertSee('mine@example.com')
            ->assertDontSee('theirs@example.com');
    }

    public function test_unauthorized_user_cannot_add(): void
    {
        Gate::define('edit-content', fn () => false);

        Livewire::test(BlacklistPage::class)
            ->set('type', 'email')
            ->set('value', 'blocked@example.com')
            ->call('add')
            ->assertForbidden();

        $this->assertSame(0, Blacklist::query()->count());
    }

    public function test_unauthorized_user_cannot_remove(): void
    {
        $entry = Blacklist::create([
            'team_id' => $this->team->id,
            'type' => 'keyword',
            'value' => 'unsubscribe',
            'added_by' => $this->user->id,
        ]);

        Gate::define('edit-content', fn () => false);

        Livewire::test(BlacklistPage::class)
            ->call('remove', $entry->id)
            ->assertForbidden();

        $this->assertSame(1, Blacklist::query()->count());
    }
}
