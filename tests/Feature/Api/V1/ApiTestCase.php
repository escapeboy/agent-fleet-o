<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->team = Team::create([
            'name' => 'Test Team',
            'slug' => 'test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);

        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    protected function actingAsApiUser(array $abilities = ['*']): static
    {
        Sanctum::actingAs($this->user, $abilities);

        return $this;
    }

    protected function createTeamMember(string $role = 'member'): User
    {
        $member = User::factory()->create([
            'current_team_id' => $this->team->id,
        ]);
        $this->team->users()->attach($member, ['role' => $role]);

        return $member;
    }
}
