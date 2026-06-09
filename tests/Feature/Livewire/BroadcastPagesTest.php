<?php

namespace Tests\Feature\Livewire;

use App\Domain\Audience\Models\Audience;
use App\Domain\Broadcast\Models\Broadcast;
use App\Domain\Shared\Enums\TeamRole;
use App\Domain\Shared\Models\Team;
use App\Livewire\Broadcast\BroadcastListPage;
use App\Livewire\Broadcast\CreateBroadcastForm;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class BroadcastPagesTest extends TestCase
{
    use RefreshDatabase;

    private function loggedInOwner(): User
    {
        $user = User::factory()->create();
        $team = Team::create([
            'name' => 'Broadcast Test',
            'slug' => 'broadcast-'.uniqid(),
            'owner_id' => $user->id,
            'settings' => [],
        ]);
        $team->users()->attach($user, ['role' => TeamRole::Owner->value]);
        $user->update(['current_team_id' => $team->id]);
        $this->actingAs($user);

        return $user;
    }

    public function test_list_shows_only_current_team_broadcasts(): void
    {
        $user = $this->loggedInOwner();
        $teamId = $user->current_team_id;

        $audience = Audience::factory()->create(['team_id' => $teamId]);
        $mine = Broadcast::factory()->create([
            'team_id' => $teamId,
            'audience_id' => $audience->id,
            'name' => 'My Team Broadcast',
        ]);

        $otherTeam = Team::factory()->create();
        $otherAudience = Audience::factory()->create(['team_id' => $otherTeam->id]);
        Broadcast::factory()->create([
            'team_id' => $otherTeam->id,
            'audience_id' => $otherAudience->id,
            'name' => 'Other Team Broadcast',
        ]);

        Livewire::test(BroadcastListPage::class)
            ->assertOk()
            ->assertSee('My Team Broadcast')
            ->assertDontSee('Other Team Broadcast');
    }

    public function test_create_aborts_when_not_authorized(): void
    {
        $user = $this->loggedInOwner();
        $audience = Audience::factory()->create(['team_id' => $user->current_team_id]);

        Gate::define('edit-content', fn () => false);

        Livewire::test(CreateBroadcastForm::class)
            ->set('audienceId', $audience->id)
            ->set('name', 'Blocked')
            ->set('subject', 'Blocked')
            ->set('body', '<p>Blocked</p>')
            ->call('create')
            ->assertForbidden();

        $this->assertDatabaseMissing('broadcasts', ['name' => 'Blocked']);
    }

    public function test_create_with_another_teams_audience_fails_scoped_exists(): void
    {
        $this->loggedInOwner();

        $otherTeam = Team::factory()->create();
        $otherAudience = Audience::factory()->create(['team_id' => $otherTeam->id]);

        Livewire::test(CreateBroadcastForm::class)
            ->set('audienceId', $otherAudience->id)
            ->set('name', 'Cross Tenant')
            ->set('subject', 'Cross Tenant')
            ->set('body', '<p>Cross Tenant</p>')
            ->call('create')
            ->assertHasErrors(['audienceId']);

        $this->assertDatabaseMissing('broadcasts', ['name' => 'Cross Tenant']);
    }

    public function test_create_with_own_audience_succeeds_and_redirects(): void
    {
        $user = $this->loggedInOwner();
        $audience = Audience::factory()->create(['team_id' => $user->current_team_id]);

        Livewire::test(CreateBroadcastForm::class)
            ->set('audienceId', $audience->id)
            ->set('name', 'Launch Email')
            ->set('subject', 'We launched')
            ->set('body', '<p>Hello</p>')
            ->call('create')
            ->assertHasNoErrors()
            ->assertRedirect();

        $this->assertDatabaseHas('broadcasts', [
            'name' => 'Launch Email',
            'audience_id' => $audience->id,
            'team_id' => $user->current_team_id,
            'status' => 'draft',
        ]);
    }
}
