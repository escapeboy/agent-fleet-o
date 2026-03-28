<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Models\Workflow;
use App\Mcp\Tools\Workflow\WorkflowDisableGatewayTool;
use App\Mcp\Tools\Workflow\WorkflowEnableGatewayTool;
use App\Mcp\Tools\Workflow\WorkflowListGatewayToolsTool;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class WorkflowGatewayTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->workflow = Workflow::factory()->create([
            'team_id' => $this->team->id,
            'name' => 'Test Workflow',
            'description' => 'A test workflow',
        ]);
    }

    // ── Model / migration ──────────────────────────────────────────────────

    public function test_workflow_has_mcp_gateway_columns(): void
    {
        $this->assertFalse($this->workflow->mcp_exposed);
        $this->assertNull($this->workflow->mcp_tool_name);
        $this->assertSame('async', $this->workflow->mcp_execution_mode);
    }

    public function test_workflow_mcp_exposed_cast_to_boolean(): void
    {
        $this->workflow->update(['mcp_exposed' => true, 'mcp_tool_name' => 'test_tool']);
        $this->workflow->refresh();

        $this->assertTrue($this->workflow->mcp_exposed);
    }

    public function test_mcp_tool_name_unique_constraint(): void
    {
        $this->workflow->update(['mcp_exposed' => true, 'mcp_tool_name' => 'unique_tool']);

        $other = Workflow::factory()->create(['team_id' => $this->team->id]);

        $this->expectException(QueryException::class);
        $other->update(['mcp_tool_name' => 'unique_tool']);
    }

    // ── workflow_enable_gateway tool ───────────────────────────────────────

    public function test_enable_gateway_sets_mcp_exposed_and_tool_name(): void
    {
        app()->bind('mcp.team_id', fn () => $this->team->id);

        $tool = app(WorkflowEnableGatewayTool::class);
        $request = $this->makeMcpRequest([
            'workflow_id' => $this->workflow->id,
            'tool_name' => 'send_weekly_report',
        ]);

        $response = $tool->handle($request);
        $data = $this->responseJson($response);

        $this->assertTrue($data['success']);
        $this->assertSame('send_weekly_report', $data['tool_name']);
        $this->assertSame('async', $data['mcp_execution_mode']);

        $this->workflow->refresh();
        $this->assertTrue($this->workflow->mcp_exposed);
        $this->assertSame('send_weekly_report', $this->workflow->mcp_tool_name);
    }

    public function test_enable_gateway_rejects_invalid_tool_name(): void
    {
        app()->bind('mcp.team_id', fn () => $this->team->id);

        $tool = app(WorkflowEnableGatewayTool::class);
        $request = $this->makeMcpRequest([
            'workflow_id' => $this->workflow->id,
            'tool_name' => 'Invalid-Name!',
        ]);

        $this->expectException(ValidationException::class);
        $tool->handle($request);
    }

    public function test_enable_gateway_rejects_duplicate_tool_name(): void
    {
        $other = Workflow::factory()->create([
            'team_id' => $this->team->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'existing_tool',
        ]);

        app()->bind('mcp.team_id', fn () => $this->team->id);

        $tool = app(WorkflowEnableGatewayTool::class);
        $request = $this->makeMcpRequest([
            'workflow_id' => $this->workflow->id,
            'tool_name' => 'existing_tool',
        ]);

        $response = $tool->handle($request);
        $this->assertStringContainsString('already in use', (string) $response->content());
    }

    public function test_enable_gateway_sets_sync_mode(): void
    {
        app()->bind('mcp.team_id', fn () => $this->team->id);

        $tool = app(WorkflowEnableGatewayTool::class);
        $request = $this->makeMcpRequest([
            'workflow_id' => $this->workflow->id,
            'tool_name' => 'sync_workflow',
            'mcp_execution_mode' => 'sync',
        ]);

        $tool->handle($request);
        $this->workflow->refresh();

        $this->assertSame('sync', $this->workflow->mcp_execution_mode);
    }

    // ── workflow_disable_gateway tool ──────────────────────────────────────

    public function test_disable_gateway_clears_mcp_fields(): void
    {
        $this->workflow->update([
            'mcp_exposed' => true,
            'mcp_tool_name' => 'my_tool',
        ]);

        app()->bind('mcp.team_id', fn () => $this->team->id);

        $tool = app(WorkflowDisableGatewayTool::class);
        $request = $this->makeMcpRequest(['workflow_id' => $this->workflow->id]);

        $response = $tool->handle($request);
        $data = $this->responseJson($response);

        $this->assertTrue($data['success']);
        $this->assertSame('my_tool', $data['removed_tool_name']);

        $this->workflow->refresh();
        $this->assertFalse($this->workflow->mcp_exposed);
        $this->assertNull($this->workflow->mcp_tool_name);
    }

    public function test_disable_gateway_is_idempotent_when_not_exposed(): void
    {
        app()->bind('mcp.team_id', fn () => $this->team->id);

        $tool = app(WorkflowDisableGatewayTool::class);
        $request = $this->makeMcpRequest(['workflow_id' => $this->workflow->id]);

        $response = $tool->handle($request);
        $data = $this->responseJson($response);

        $this->assertTrue($data['success']);
    }

    // ── workflow_list_gateway_tools tool ───────────────────────────────────

    public function test_list_gateway_tools_returns_exposed_workflows(): void
    {
        Workflow::factory()->create([
            'team_id' => $this->team->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'tool_alpha',
        ]);
        Workflow::factory()->create([
            'team_id' => $this->team->id,
            'mcp_exposed' => false,
        ]);

        $tool = app(WorkflowListGatewayToolsTool::class);
        $request = $this->makeMcpRequest([]);

        $response = $tool->handle($request);
        $data = $this->responseJson($response);

        $this->assertSame(1, $data['count']);
        $this->assertSame('tool_alpha', $data['tools'][0]['tool_name']);
    }

    public function test_list_gateway_tools_is_cross_team(): void
    {
        $otherTeam = Team::factory()->create();
        Workflow::factory()->create([
            'team_id' => $otherTeam->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'other_teams_tool',
        ]);
        Workflow::factory()->create([
            'team_id' => $this->team->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'my_teams_tool',
        ]);

        $tool = app(WorkflowListGatewayToolsTool::class);
        $request = $this->makeMcpRequest([]);

        $response = $tool->handle($request);
        $data = $this->responseJson($response);

        // Should see both teams' tools (cross-team listing)
        $this->assertSame(2, $data['count']);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $data
     */
    private function makeMcpRequest(array $data): Request
    {
        return new Request($data);
    }

    /**
     * @return array<string, mixed>
     */
    private function responseJson(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }
}
