<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp\Tools\Workflow;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Mcp\Tools\Workflow\WorkflowSimulateTool;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Tests\TestCase;

class WorkflowSimulateToolTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Simulate Team',
            'slug' => 'simulate-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);

        app()->instance('mcp.team_id', $this->team->id);
    }

    private function decode(Response $response): array
    {
        return json_decode((string) $response->content(), true);
    }

    /** Build a linear Start -> Agent -> End graph. */
    private function linearWorkflow(Team $team): Workflow
    {
        $workflow = Workflow::factory()->for($team)->create();
        $start = WorkflowNode::factory()->for($workflow)->start()->create();
        $agent = WorkflowNode::factory()->for($workflow)->create(['label' => 'Do Work']);
        $end = WorkflowNode::factory()->for($workflow)->end()->create();

        WorkflowEdge::factory()->for($workflow)->create([
            'source_node_id' => $start->id,
            'target_node_id' => $agent->id,
        ]);
        WorkflowEdge::factory()->for($workflow)->create([
            'source_node_id' => $agent->id,
            'target_node_id' => $end->id,
        ]);

        return $workflow;
    }

    public function test_simulates_linear_workflow_to_completion(): void
    {
        $workflow = $this->linearWorkflow($this->team);

        $response = (new WorkflowSimulateTool)->handle(new Request(['workflow_id' => $workflow->id]));

        $this->assertFalse($response->isError());
        $payload = $this->decode($response);

        $this->assertSame($workflow->id, $payload['workflow_id']);
        $this->assertSame('completed', $payload['termination_status']);
        $this->assertCount(3, $payload['executed_path']);
        $this->assertSame('end', end($payload['executed_path'])['type']);
    }

    public function test_cross_tenant_workflow_returns_not_found(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other Simulate Team',
            'slug' => 'other-simulate-team',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        $workflow = $this->linearWorkflow($otherTeam);

        $response = (new WorkflowSimulateTool)->handle(new Request(['workflow_id' => $workflow->id]));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame('NOT_FOUND', $payload['error']['code']);
    }

    public function test_missing_team_returns_permission_denied(): void
    {
        app()->forgetInstance('mcp.team_id');
        Auth::logout();

        $workflow = $this->linearWorkflow($this->team);

        $response = (new WorkflowSimulateTool)->handle(new Request(['workflow_id' => $workflow->id]));

        $this->assertTrue($response->isError());
        $payload = json_decode((string) $response->content(), true);
        $this->assertSame('PERMISSION_DENIED', $payload['error']['code']);
    }
}
