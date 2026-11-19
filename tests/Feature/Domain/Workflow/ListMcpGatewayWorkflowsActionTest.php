<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\ListMcpGatewayWorkflowsAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListMcpGatewayWorkflowsActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_only_exposed_named_workflows_of_the_given_team(): void
    {
        $team = Team::factory()->create();
        $otherTeam = Team::factory()->create();

        $mine = Workflow::factory()->create([
            'team_id' => $team->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'my_tool',
        ]);
        Workflow::factory()->create([
            'team_id' => $otherTeam->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'other_team_tool',
        ]);
        Workflow::factory()->create([
            'team_id' => $team->id,
            'mcp_exposed' => false,
            'mcp_tool_name' => 'unexposed_tool',
        ]);
        Workflow::factory()->create([
            'team_id' => $team->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => null,
        ]);

        $result = app(ListMcpGatewayWorkflowsAction::class)->execute($team->id);

        $this->assertCount(1, $result);
        $this->assertSame($mine->id, $result->first()?->getKey());
    }

    public function test_returns_empty_collection_without_team(): void
    {
        $team = Team::factory()->create();
        Workflow::factory()->create([
            'team_id' => $team->id,
            'mcp_exposed' => true,
            'mcp_tool_name' => 'some_tool',
        ]);

        $this->assertCount(0, app(ListMcpGatewayWorkflowsAction::class)->execute(null));
    }
}
