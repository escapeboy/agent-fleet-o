<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

abstract class ApiTestCase extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Team $team;

    protected function tearDown(): void
    {
        parent::tearDown();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

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
