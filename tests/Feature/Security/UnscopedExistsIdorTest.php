<?php

namespace Tests\Feature\Security;

use App\Domain\Agent\Models\Agent;
use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Credential\Models\Credential;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Regression coverage for the unscoped Laravel `exists:table,column`
 * validation cluster discovered in the 2026-04-09 follow-up audit. Each
 * REST/MCP/Livewire endpoint that previously accepted any global UUID for
 * relationship binding now scopes the existence check to the caller's team,
 * so cross-tenant grafting attacks return 422 instead of succeeding.
 *
 * Also covers the ApproveAction TOCTOU fix: a second concurrent approve
 * call must reject as "not pending" once the first has flipped the row.
 */
class UnscopedExistsIdorTest extends TestCase
{
    use RefreshDatabase;

    private Team $teamA;

    private Team $teamB;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $ownerA = User::factory()->create();
        $ownerB = User::factory()->create();

        $this->teamA = Team::create([
            'name' => 'Team A',
            'slug' => 'team-a-'.uniqid(),
            'owner_id' => $ownerA->id,
            'settings' => [],
        ]);

        $this->teamB = Team::create([
            'name' => 'Team B',
            'slug' => 'team-b-'.uniqid(),
            'owner_id' => $ownerB->id,
            'settings' => [],
        ]);

        $this->userB = $ownerB;
        $this->userB->current_team_id = $this->teamB->id;
        $this->userB->save();
    }

    public function test_store_tool_rejects_cross_tenant_credential_id(): void
    {
        $foreignCredential = Credential::factory()->create(['team_id' => $this->teamA->id]);

        $response = $this->actingAs($this->userB)
            ->postJson('/api/v1/tools', [
                'name' => 'Pwned tool',
                'type' => 'mcp_stdio',
                'transport_config' => ['command' => '/bin/echo'],
                'credential_id' => $foreignCredential->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('credential_id');
    }

    public function test_store_tool_accepts_own_team_credential_id(): void
    {
        $ownCredential = Credential::factory()->create(['team_id' => $this->teamB->id]);

        $response = $this->actingAs($this->userB)
            ->postJson('/api/v1/tools', [
                'name' => 'Legit tool',
                'type' => 'mcp_stdio',
                'transport_config' => ['command' => '/bin/echo'],
                'credential_id' => $ownCredential->id,
            ]);

        // Validation must pass; controller may still 200/201 depending on action
        $response->assertJsonMissingValidationErrors('credential_id');
    }

    public function test_store_chatbot_rejects_cross_tenant_agent_id(): void
    {
        // Enable chatbot feature on team B so we don't get a 403 before validation
        $this->teamB->settings = ['chatbot_enabled' => true];
        $this->teamB->save();

        $foreignAgent = Agent::factory()->create(['team_id' => $this->teamA->id]);

        $response = $this->actingAs($this->userB)
            ->postJson('/api/v1/chatbot-instances', [
                'name' => 'Pwned chatbot',
                'type' => 'agentic',
                'agent_id' => $foreignAgent->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('agent_id');
    }

    public function test_store_experiment_rejects_cross_tenant_workflow_id(): void
    {
        $foreignWorkflow = Workflow::factory()->create([
            'team_id' => $this->teamA->id,
            'status' => WorkflowStatus::Active,
        ]);

        $response = $this->actingAs($this->userB)
            ->postJson('/api/v1/experiments', [
                'title' => 'Pwned experiment',
                'thesis' => 'Bind to a foreign workflow.',
                'track' => 'standard',
                'workflow_id' => $foreignWorkflow->id,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('workflow_id');
    }

    public function test_approve_action_serializes_concurrent_calls(): void
    {
        // Build a credential-review approval (simplest path that doesn't need a
        // full Experiment + transition stack).
        $credential = Credential::factory()->create(['team_id' => $this->teamB->id]);

        $approval = ApprovalRequest::create([
            'team_id' => $this->teamB->id,
            'credential_id' => $credential->id,
            'request_type' => 'credential_review',
            'status' => ApprovalStatus::Pending,
            'requested_by' => $this->userB->id,
            'metadata' => [],
            'expires_at' => now()->addDay(),
        ]);

        $action = $this->app->make(ApproveAction::class);

        $action->execute($approval, $this->userB->id);

        // Second call must throw — even if the caller still holds the original
        // model with the stale "pending" status, the lockForUpdate re-fetch
        // sees the freshly approved row and rejects.
        $stale = ApprovalRequest::query()->find($approval->id);
        $stale->status = ApprovalStatus::Pending;

        $this->expectException(InvalidArgumentException::class);
        $action->execute($stale, $this->userB->id);
    }
}
