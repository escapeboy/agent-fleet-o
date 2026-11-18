<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\EnableWorkflowMcpGatewayAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EnableWorkflowMcpGatewayActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Workflow $workflow;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->workflow = Workflow::factory()->create(['team_id' => $this->team->id]);
    }

    public function test_sets_gateway_columns_with_async_default(): void
    {
        $result = app(EnableWorkflowMcpGatewayAction::class)->execute($this->workflow, 'weekly_report');

        $this->workflow->refresh();
        $this->assertTrue($this->workflow->mcp_exposed);
        $this->assertSame('weekly_report', $this->workflow->mcp_tool_name);
        $this->assertSame('async', $this->workflow->mcp_execution_mode);
        $this->assertSame($this->workflow->id, $result->id);
    }

    public function test_sets_sync_mode(): void
    {
        app(EnableWorkflowMcpGatewayAction::class)->execute($this->workflow, 'weekly_report', 'sync');

        $this->assertSame('sync', $this->workflow->refresh()->mcp_execution_mode);
    }

    public function test_allows_keeping_its_own_name(): void
    {
        $this->workflow->update(['mcp_exposed' => true, 'mcp_tool_name' => 'weekly_report']);

        app(EnableWorkflowMcpGatewayAction::class)->execute($this->workflow, 'weekly_report', 'sync');

        $this->assertSame('sync', $this->workflow->refresh()->mcp_execution_mode);
    }

    public function test_rejects_invalid_name(): void
    {
        $this->assertRejected('Invalid-Name!', 'async');
        $this->assertRejected('ab', 'async');
    }

    public function test_rejects_name_used_by_another_teams_workflow(): void
    {
        $otherTeam = Team::factory()->create();
        Workflow::factory()->create([
            'team_id' => $otherTeam->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'taken_name',
        ]);

        $this->assertRejected('taken_name', 'async');
    }

    public function test_rejects_invalid_mode(): void
    {
        $this->assertRejected('weekly_report', 'later', 'mcp_execution_mode');
    }

    private function assertRejected(string $name, string $mode, string $errorKey = 'tool_name'): void
    {
        try {
            app(EnableWorkflowMcpGatewayAction::class)->execute($this->workflow, $name, $mode);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($errorKey, $e->errors());
        }

        $this->workflow->refresh();
        $this->assertFalse($this->workflow->mcp_exposed);
        $this->assertNull($this->workflow->mcp_tool_name);
    }
}
