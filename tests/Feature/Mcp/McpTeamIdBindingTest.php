<?php

namespace Tests\Feature\Mcp;

use App\Domain\Shared\Models\Team;
use App\Http\Middleware\McpTeamBinding;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Locks in the contract that ONLY the McpTeamBinding middleware (or cloud's
 * McpTeamContext, or BootstrapsMcpAuth in stdio) populates `mcp.team_id`.
 *
 * Background: a previous attempt pre-bound `mcp.team_id` in AppServiceProvider
 * to fix the Compact umbrella INTERNAL error reported by Barsy. Two pre-bind
 * shapes were considered, both wrong:
 *
 *   - `instance($key, null)` — null-blind via isset(); bound() returns false
 *     anyway, so callers using the defensive `bound() ? app() : fallback`
 *     pattern fall back correctly, but bare `app()` callers still throw
 *     (BindingResolutionException). This is the original buggy state.
 *
 *   - `bind($key, fn () => null)` — binds a non-null entry to $bindings, so
 *     bound() returns true, app() returns null. This FIXES bare callers but
 *     BREAKS the 15+ tools that use the defensive pattern: they see bound() ==
 *     true and use the null value instead of falling back to auth, producing
 *     "No current team." errors throughout the Livewire surface.
 *
 * The correct fix is to bind from middleware (with the actual team ID) and
 * leave the binding absent everywhere else. This test asserts both.
 */
class McpTeamIdBindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_binding_is_absent_outside_mcp_request_context(): void
    {
        // Fresh app boot — no McpTeamBinding middleware has run.
        $this->app->forgetInstance('mcp.team_id');

        $this->assertFalse($this->app->bound('mcp.team_id'),
            'mcp.team_id MUST be unbound outside MCP request context. The defensive '
            .'`bound() ? app() : fallback` pattern in 15+ tools relies on bound()==false '
            .'to fall back to auth()->user()->current_team_id.');
    }

    public function test_middleware_binds_team_id_from_authenticated_user(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $user->update(['current_team_id' => $team->id]);

        $request = Request::create('/mcp', 'POST');
        $request->setUserResolver(fn () => $user);

        $this->app->forgetInstance('mcp.team_id');

        (new McpTeamBinding)->handle($request, function () use ($team) {
            $this->assertTrue($this->app->bound('mcp.team_id'));
            $this->assertSame($team->id, app('mcp.team_id'));

            return response('ok');
        });
    }

    public function test_middleware_skips_binding_when_user_has_no_team(): void
    {
        $user = User::factory()->create(['current_team_id' => null]);

        $request = Request::create('/mcp', 'POST');
        $request->setUserResolver(fn () => $user);

        $this->app->forgetInstance('mcp.team_id');

        (new McpTeamBinding)->handle($request, function () {
            // Still unbound — caller's defensive pattern falls back to auth's null
            // and tools return "No current team." gracefully (not the original
            // BindingResolutionException).
            $this->assertFalse($this->app->bound('mcp.team_id'));

            return response('ok');
        });
    }

    public function test_middleware_skips_binding_when_no_user(): void
    {
        $request = Request::create('/mcp', 'POST');
        // No user resolver — represents an unauthenticated request reaching
        // the middleware (auth:sanctum normally rejects this earlier).

        $this->app->forgetInstance('mcp.team_id');

        (new McpTeamBinding)->handle($request, function () {
            $this->assertFalse($this->app->bound('mcp.team_id'));

            return response('ok');
        });
    }
}
