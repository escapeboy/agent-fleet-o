<?php

namespace Tests\Feature\Domain\Workflow;

use App\Domain\Agent\Enums\AgentStatus;
use App\Domain\Agent\Models\Agent;
use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ResolveAgentToolsAction;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Workflow\Enums\WorkflowNodeType;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowEdge;
use App\Domain\Workflow\Models\WorkflowNode;
use App\Domain\Workflow\Services\SynchronousWorkflowExecutor;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class SynchronousWorkflowExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Mock AI gateway to prevent LocalBridgeGateway DI resolution errors in test
        $this->app->instance(AiGatewayInterface::class, Mockery::mock(AiGatewayInterface::class));
    }

    public function test_workflow_tool_type_enum(): void
    {
        $type = ToolType::Workflow;
        $this->assertSame('workflow', $type->value);
        $this->assertSame('Workflow', $type->label());
        $this->assertFalse($type->isMcp());
    }

    public function test_rejects_workflow_with_human_task_nodes(): void
    {
        $team = Team::factory()->create();
        $workflow = Workflow::factory()->create([
            'team_id' => $team->id,
            'status' => WorkflowStatus::Active,
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Start,
            'label' => 'Start',
            'order' => 0,
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::HumanTask,
            'label' => 'Review',
            'order' => 1,
            'config' => ['form_schema' => []],
        ]);

        $executor = app(SynchronousWorkflowExecutor::class);
        $result = $executor->execute(
            workflow: $workflow,
            teamId: $team->id,
            userId: 'test-user',
            input: ['goal' => 'test'],
        );

        $this->assertStringContainsString('human_task', $result);
        $this->assertStringContainsString('Error', $result);
    }

    public function test_rejects_when_max_depth_exceeded(): void
    {
        $team = Team::factory()->create();
        $workflow = Workflow::factory()->create([
            'team_id' => $team->id,
            'status' => WorkflowStatus::Active,
        ]);

        $executor = app(SynchronousWorkflowExecutor::class);
        $result = $executor->execute(
            workflow: $workflow,
            teamId: $team->id,
            userId: 'test-user',
            input: ['goal' => 'test'],
            currentDepth: 100, // way beyond any limit
        );

        $this->assertStringContainsString('depth', $result);
        $this->assertStringContainsString('Error', $result);
    }

    public function test_returns_error_for_empty_workflow(): void
    {
        $team = Team::factory()->create();
        $workflow = Workflow::factory()->create([
            'team_id' => $team->id,
            'status' => WorkflowStatus::Active,
        ]);

        $executor = app(SynchronousWorkflowExecutor::class);
        $result = $executor->execute(
            workflow: $workflow,
            teamId: $team->id,
            userId: 'test-user',
            input: ['goal' => 'test'],
        );

        $this->assertStringContainsString('no nodes', strtolower($result));
    }

    public function test_returns_error_for_missing_start_node(): void
    {
        $team = Team::factory()->create();
        $workflow = Workflow::factory()->create([
            'team_id' => $team->id,
            'status' => WorkflowStatus::Active,
        ]);

        // Only an end node, no start
        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::End,
            'label' => 'End',
            'order' => 0,
        ]);

        $executor = app(SynchronousWorkflowExecutor::class);
        $result = $executor->execute(
            workflow: $workflow,
            teamId: $team->id,
            userId: 'test-user',
            input: ['goal' => 'test'],
        );

        $this->assertStringContainsString('start node', strtolower($result));
    }

    public function test_agent_callable_workflow_ids_builds_tools(): void
    {
        $team = Team::factory()->create();

        $workflow = Workflow::factory()->create([
            'team_id' => $team->id,
            'status' => WorkflowStatus::Active,
            'name' => 'Research Pipeline',
        ]);

        // Add start and end nodes (no human_task)
        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Start,
            'label' => 'Start',
            'order' => 0,
        ]);
        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::End,
            'label' => 'End',
            'order' => 1,
        ]);

        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'status' => AgentStatus::Active,
            'config' => [
                'callable_workflow_ids' => [$workflow->id],
            ],
        ]);

        $resolver = app(ResolveAgentToolsAction::class);
        $tools = $resolver->execute($agent);

        // Should have at least one workflow tool
        $workflowTools = array_filter($tools, fn ($t) => str_starts_with($t->name(), 'run_workflow_'));
        $this->assertNotEmpty($workflowTools);
    }

    public function test_skips_workflow_tools_at_max_depth(): void
    {
        $team = Team::factory()->create();

        $workflow = Workflow::factory()->create([
            'team_id' => $team->id,
            'status' => WorkflowStatus::Active,
            'name' => 'Test Workflow',
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Start,
            'label' => 'Start',
            'order' => 0,
        ]);

        $agent = Agent::factory()->create([
            'team_id' => $team->id,
            'status' => AgentStatus::Active,
            'config' => [
                'callable_workflow_ids' => [$workflow->id],
            ],
        ]);

        // Set depth to max
        $resolver = app(ResolveAgentToolsAction::class);
        $tools = $resolver->execute($agent, agentToolDepth: 100);

        $workflowTools = array_filter($tools, fn ($t) => str_starts_with($t->name(), 'run_workflow_'));
        $this->assertEmpty($workflowTools);
    }

    public function test_creates_ephemeral_experiment_on_execution(): void
    {
        $team = Team::factory()->create();
        $user = User::factory()->create();
        $team->users()->attach($user);

        $workflow = Workflow::factory()->create([
            'team_id' => $team->id,
            'status' => WorkflowStatus::Active,
            'name' => 'Test Pipeline',
        ]);

        $start = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::Start,
            'label' => 'Start',
            'order' => 0,
        ]);

        $end = WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => WorkflowNodeType::End,
            'label' => 'End',
            'order' => 1,
        ]);

        WorkflowEdge::create([
            'workflow_id' => $workflow->id,
            'source_node_id' => $start->id,
            'target_node_id' => $end->id,
        ]);

        $executor = app(SynchronousWorkflowExecutor::class);
        $executor->execute(
            workflow: $workflow,
            teamId: $team->id,
            userId: $user->id,
            input: ['goal' => 'Test execution'],
        );

        $this->assertDatabaseHas('experiments', [
            'workflow_id' => $workflow->id,
            'team_id' => $team->id,
            'title' => 'Workflow Tool: Test Pipeline',
        ]);
    }
}
