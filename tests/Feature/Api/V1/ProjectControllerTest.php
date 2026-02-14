<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Project\Models\Project;

class ProjectControllerTest extends ApiTestCase
{
    private function createProject(array $overrides = []): Project
    {
        return Project::create(array_merge([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test Project',
            'type' => 'one_shot',
            'status' => 'draft',
            'agent_config' => [],
            'budget_config' => [],
            'notification_config' => [],
            'settings' => [],
        ], $overrides));
    }

    public function test_can_list_projects(): void
    {
        $this->actingAsApiUser();
        $this->createProject(['title' => 'Project One']);
        $this->createProject(['title' => 'Project Two']);

        $response = $this->getJson('/api/v1/projects');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'title', 'status', 'type']],
            ]);
    }

    public function test_can_filter_projects_by_status(): void
    {
        $this->actingAsApiUser();
        $this->createProject(['title' => 'Draft', 'status' => 'draft']);
        $this->createProject(['title' => 'Active', 'status' => 'active']);

        $response = $this->getJson('/api/v1/projects?filter[status]=draft');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Draft');
    }

    public function test_can_filter_projects_by_type(): void
    {
        $this->actingAsApiUser();
        $this->createProject(['title' => 'One Shot', 'type' => 'one_shot']);
        $this->createProject(['title' => 'Continuous', 'type' => 'continuous']);

        $response = $this->getJson('/api/v1/projects?filter[type]=continuous');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Continuous');
    }

    public function test_can_show_project(): void
    {
        $this->actingAsApiUser();
        $project = $this->createProject();

        $response = $this->getJson("/api/v1/projects/{$project->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $project->id)
            ->assertJsonPath('data.title', 'Test Project');
    }

    public function test_can_create_project(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/projects', [
            'title' => 'New Project',
            'type' => 'one_shot',
            'description' => 'A test project',
            'goal' => 'Test goal',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'New Project')
            ->assertJsonPath('data.type', 'one_shot');

        $this->assertDatabaseHas('projects', ['title' => 'New Project']);
    }

    public function test_create_project_requires_title(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/projects', [
            'type' => 'one_shot',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['title']);
    }

    public function test_create_project_requires_type(): void
    {
        $this->actingAsApiUser();

        $response = $this->postJson('/api/v1/projects', [
            'title' => 'Missing Type',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_can_update_project(): void
    {
        $this->actingAsApiUser();
        $project = $this->createProject();

        $response = $this->putJson("/api/v1/projects/{$project->id}", [
            'title' => 'Updated Project',
            'description' => 'Updated description',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.title', 'Updated Project');
    }

    public function test_can_archive_project(): void
    {
        $this->actingAsApiUser();
        $project = $this->createProject();

        $response = $this->deleteJson("/api/v1/projects/{$project->id}");

        $response->assertOk()
            ->assertJson(['message' => 'Project archived.']);
    }

    public function test_can_activate_draft_project(): void
    {
        $this->actingAsApiUser();
        $project = $this->createProject(['status' => 'draft']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/activate");

        $response->assertOk()
            ->assertJsonPath('data.status', 'active');
    }

    public function test_cannot_activate_non_draft_project(): void
    {
        $this->actingAsApiUser();
        $project = $this->createProject(['status' => 'active']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/activate");

        $response->assertStatus(422);
    }

    public function test_can_pause_active_project(): void
    {
        $this->actingAsApiUser();
        $project = $this->createProject(['status' => 'active']);

        $response = $this->postJson("/api/v1/projects/{$project->id}/pause", [
            'reason' => 'Testing pause',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'paused');
    }

    public function test_can_list_project_runs(): void
    {
        $this->actingAsApiUser();
        $project = $this->createProject();

        $response = $this->getJson("/api/v1/projects/{$project->id}/runs");

        $response->assertOk()
            ->assertJsonStructure(['data']);
    }

    public function test_unauthenticated_cannot_list_projects(): void
    {
        $response = $this->getJson('/api/v1/projects');

        $response->assertStatus(401);
    }
}
