<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Models\User;
use Illuminate\Support\Facades\Redis;
use Mockery;

class BridgeRegisterTeamResolutionTest extends ApiTestCase
{
    /** @param  array<int, string>  $abilities */
    private function withBridgeToken(User $user, array $abilities): static
    {
        $this->withToken(SanctumTokenIssuer::create($user, 'bridge', $abilities)->plainTextToken);

        return $this;
    }

    private function stubBridgeRedis(): void
    {
        $connection = Mockery::mock();
        $connection->shouldReceive('get')->andReturn(null);
        $connection->shouldReceive('del')->andReturn(0);

        Redis::shouldReceive('connection')->with('bridge')->andReturn($connection);
    }

    private function userWithoutTeam(): User
    {
        return User::factory()->create(['current_team_id' => null]);
    }

    private function foreignTeam(): Team
    {
        return Team::factory()->create();
    }

    public function test_team_none_token_for_user_without_team_returns_clear_403(): void
    {
        $this->stubBridgeRedis();
        $user = $this->userWithoutTeam();

        $response = $this->withBridgeToken($user, ['team:none'])
            ->postJson('/api/v1/bridge/register', ['session_id' => 'test-session']);

        $response->assertStatus(403);
        $this->assertStringContainsString('create or select a team', strtolower((string) $response->json('message')));
        $this->assertDatabaseCount('bridge_connections', 0);
    }

    public function test_team_none_token_for_user_who_now_has_team_registers_on_current_team(): void
    {
        $this->stubBridgeRedis();

        $response = $this->withBridgeToken($this->user, ['team:none'])
            ->postJson('/api/v1/bridge/register', ['session_id' => 'test-session']);

        $response->assertStatus(201)
            ->assertJsonPath('data.team_id', $this->team->id);

        $this->assertDatabaseHas('bridge_connections', [
            'team_id' => $this->team->id,
            'session_id' => 'test-session',
        ]);
    }

    public function test_token_for_team_user_does_not_belong_to_returns_403(): void
    {
        $this->stubBridgeRedis();
        $foreign = $this->foreignTeam();

        $response = $this->withBridgeToken($this->user, ['team:'.$foreign->id])
            ->postJson('/api/v1/bridge/register', ['session_id' => 'test-session']);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('bridge_connections', ['team_id' => $foreign->id]);
    }

    public function test_fallback_to_current_team_user_was_removed_from_returns_403(): void
    {
        $this->stubBridgeRedis();
        $foreign = $this->foreignTeam();
        $user = User::factory()->create(['current_team_id' => $foreign->id]);

        $response = $this->withBridgeToken($user, ['team:none'])
            ->postJson('/api/v1/bridge/register', ['session_id' => 'test-session']);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('bridge_connections', ['team_id' => $foreign->id]);
    }

    public function test_valid_team_token_registers_on_token_team(): void
    {
        $this->stubBridgeRedis();

        $response = $this->withBridgeToken($this->user, ['team:'.$this->team->id])
            ->postJson('/api/v1/bridge/register', ['session_id' => 'test-session']);

        $response->assertStatus(201)
            ->assertJsonPath('data.team_id', $this->team->id);
    }

    public function test_status_with_team_none_token_and_no_team_returns_403(): void
    {
        $user = $this->userWithoutTeam();

        $response = $this->withBridgeToken($user, ['team:none'])
            ->getJson('/api/v1/bridge/status');

        $response->assertStatus(403);
        $this->assertStringContainsString('create or select a team', strtolower((string) $response->json('message')));
    }
}
