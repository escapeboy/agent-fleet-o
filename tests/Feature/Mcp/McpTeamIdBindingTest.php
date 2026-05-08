<?php

namespace Tests\Feature\Mcp;

use Tests\TestCase;

/**
 * Locks in the contract that `app('mcp.team_id')` ALWAYS resolves without throwing,
 * even on request paths that don't have an MCP middleware to populate the binding.
 *
 * Regression: a previous `instance('mcp.team_id', null)` pre-bind was null-blind
 * because Laravel's Container::resolve() uses isset() against $instances, and
 * isset() against a literal-null value returns false. The container then fell
 * through to Container::build('mcp.team_id') which threw
 * "Target class [mcp.team_id] does not exist." Compact umbrella tools (signal_manage,
 * memory_manage) hit this on the base HTTP MCP path (no McpTeamContext middleware),
 * surfacing as INTERNAL errors to spawned MCP clients.
 */
class McpTeamIdBindingTest extends TestCase
{
    public function test_mcp_team_id_resolves_to_null_when_no_middleware_set_it(): void
    {
        // Ensure no instance() override is active.
        $this->app->forgetInstance('mcp.team_id');

        $this->assertTrue($this->app->bound('mcp.team_id'),
            'mcp.team_id must be bound by AppServiceProvider before any request runs.');

        $this->assertNull(app('mcp.team_id'),
            'mcp.team_id must resolve to null (not throw) when no middleware has overwritten it.');
    }

    public function test_middleware_can_overwrite_the_binding_via_instance(): void
    {
        $this->app->bind('mcp.team_id', fn () => null);

        $this->app->instance('mcp.team_id', 'team-uuid-fixture');
        $this->assertSame('team-uuid-fixture', app('mcp.team_id'));

        // After forgetInstance, the closure is back in effect.
        $this->app->forgetInstance('mcp.team_id');
        $this->assertNull(app('mcp.team_id'));
    }
}
