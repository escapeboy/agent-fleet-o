<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Models\WorkflowNode;

class WorkflowControllerTest extends ApiTestCase
{
    private function createWorkflow(array $overrides = []): Workflow
    {
        return Workflow::create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'name' => 'Test Workflow',
            'slug' => 'test-workflow-'.uniqid(),
            'description' => 'A test workflow',
            'status' => 'draft',
            'version' => 1,
            'max_loop_iterations' => 5,
            'settings' => [],
        ], $overrides));
    }

    private function addDefaultNodes(Workflow $workflow): void
    {
        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'start',
            'label' => 'Start',
            'position_x' => 250,
            'position_y' => 50,
            'config' => [],
            'order' => 0,
        ]);

        WorkflowNode::create([
            'workflow_id' => $workflow->id,
            'type' => 'end',
            'label' => 'End',
            'position_x' => 250,
            'position_y' => 400,
            'config' => [],
            'order' => 1,
        ]);
    }

    public function test_can_list_workflows(): void
    {
        $this->actingAsApiUser();
        $this->createWorkflow(['name' => 'WF One']);
        $this->createWorkflow(['name' => 'WF Two']);

        $response = $this->getJson('/api/v1/workflows');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'name', 'status', 'version']],
            ]);
    }

    public function test_can_filter_workflows_by_status(): void
    {
        $this->actingAsApiUser();
        $this->createWorkflow(['name' => 'Draft WF', 'status' => 'draft']);
        $this->createWorkflow(['name' => 'Active WF', 'status' => 'active']);

        $response = $this->getJson('/api/v1/workflows?filter[status]=draft');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Draft WF');
    }

    public function test_can_show_workflow_with_graph(): void
    {
        $this->actingAsApiUser();
        $workflow = $this->createWorkflow();
        $this->addDefaultNodes($workflow);

        $response = $this->getJson("/api/v1/workflows/{$workflow->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $workflow->id)
            ->assertJsonCount(2, 'data.nodes');
    }

    public function test_can_create_workflow(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/workflows', [
            'name' => 'New Workflow',
            'description' => 'A brand new workflow',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.name', 'New Workflow')
            ->assertJsonPath('data.status', 'draft');

        $this->assertDatabaseHas('workflows', ['name' => 'New Workflow']);
    }

    public function test_can_update_workflow(): void
    {
        $this->actingAsApiUser();
        $workflow = $this->createWorkflow();

        $response = $this->putJson("/api/v1/workflows/{$workflow->id}", [
            'name' => 'Updated Workflow',
            'description' => 'Updated description',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.name', 'Updated Workflow');
    }

    public function test_can_delete_workflow(): void
    {
        $this->actingAsApiUser();
        $workflow = $this->createWorkflow();

        $response = $this->deleteJson("/api/v1/workflows/{$workflow->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Workflow deleted.']);
    }

    public function test_can_duplicate_workflow(): void
    {
        $this->actingAsApiUser();
        $workflow = $this->createWorkflow();
        $this->addDefaultNodes($workflow);

        $response = $this->postJson("/api/v1/workflows/{$workflow->id}/duplicate");

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'draft');

        $this->assertTrue(str_contains($response->json('data.name'), '(copy)'));
    }

    public function test_can_estimate_cost(): void
    {
        $this->actingAsApiUser();
        $workflow = $this->createWorkflow();
        $this->addDefaultNodes($workflow);

        $response = $this->getJson("/api/v1/workflows/{$workflow->id}/cost");

        $response->assertOk()
            ->assertJsonStructure(['estimated_cost_credits']);
    }

    public function test_unauthenticated_cannot_list_workflows(): void
    {
        $response = $this->getJson('/api/v1/workflows');

        $response->assertStatus(401);
    }
}
