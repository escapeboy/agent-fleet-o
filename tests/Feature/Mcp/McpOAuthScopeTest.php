<?php

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Routing\Middleware\ThrottleRequestsWithRedis;
use Illuminate\Support\Facades\Auth;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * The MCP endpoints accept a Passport token only when it carries `mcp:use`.
 * Sanctum personal access tokens keep working as before.
 */
class McpOAuthScopeTest extends TestCase
{
    use RefreshDatabase;

    private const RPC = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list', 'params' => []];

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([ThrottleRequests::class, ThrottleRequestsWithRedis::class]);

        $this->user = User::factory()->create(['email_verified_at' => now()]);
        $team = Team::create([
            'name' => 'Scope Team',
            'slug' => 'scope-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $team->id]);
        $team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_oauth_token_without_mcp_scope_is_refused_on_both_endpoints(): void
    {
        Passport::actingAs($this->user, [], 'passport');

        $response = $this->postJson('/mcp', self::RPC)
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_scope');
        $this->assertSame(
            'Bearer error="insufficient_scope", scope="mcp:use", resource_metadata="'.url('/.well-known/oauth-protected-resource/mcp').'"',
            $response->headers->get('WWW-Authenticate'),
        );

        $this->postJson('/mcp/full', self::RPC)
            ->assertStatus(403)
            ->assertJsonPath('error', 'insufficient_scope');
    }

    public function test_oauth_token_with_mcp_scope_opens_the_endpoint(): void
    {
        Passport::actingAs($this->user, ['mcp:use'], 'passport');

        $this->postJson('/mcp', self::RPC)->assertOk();
    }

    public function test_sanctum_personal_access_token_still_opens_the_endpoint(): void
    {
        Auth::forgetGuards();
        $pat = SanctumTokenIssuer::create($this->user, 'pat')->plainTextToken;

        $this->withToken($pat)->postJson('/mcp', self::RPC)->assertOk();
    }
}
