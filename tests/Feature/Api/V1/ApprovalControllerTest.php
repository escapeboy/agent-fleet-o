<?php

namespace Tests\Feature\Api\V1;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Events\ExperimentTransitioned;
use App\Domain\Experiment\Models\Experiment;
use Illuminate\Support\Facades\Event;

class ApprovalControllerTest extends ApiTestCase
{
    private function createExperiment(array $overrides = []): Experiment
    {
        return Experiment::create(array_merge([
            'team_id' => $this->team->id,
            'title' => 'Test Experiment',
            'thesis' => 'Testing hypothesis',
            'track' => 'growth',
            'status' => 'awaiting_approval',
            'constraints' => [],
            'success_criteria' => [],
            'user_id' => $this->user->id,
            'budget_spent_credits' => 0,
        ], $overrides));
    }

    private function createApproval(array $overrides = []): ApprovalRequest
    {
        $experiment = $overrides['experiment'] ?? $this->createExperiment();
        unset($overrides['experiment']);

        return ApprovalRequest::create(array_merge([
            'team_id' => $this->team->id,
            'experiment_id' => $experiment->id,
            'status' => 'pending',
            'context' => ['stage' => 'building'],
            'expires_at' => now()->addHours(24),
        ], $overrides));
    }

    public function test_can_list_approvals(): void
    {
        $this->actingAsApiUser();
        $this->createApproval();
        $this->createApproval();

        $response = $this->getJson('/api/v1/approvals');

        $response->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure([
                'data' => [['id', 'experiment_id', 'status', 'expires_at']],
            ]);
    }

    public function test_can_filter_approvals_by_status(): void
    {
        $this->actingAsApiUser();
        $this->createApproval(['status' => 'pending']);
        $this->createApproval(['status' => 'approved']);

        $response = $this->getJson('/api/v1/approvals?filter[status]=pending');

        $response->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', 'pending');
    }

    public function test_can_show_approval(): void
    {
        $this->actingAsApiUser();
        $approval = $this->createApproval();

        $response = $this->getJson("/api/v1/approvals/{$approval->id}");

        $response->assertOk()
            ->assertJsonPath('data.id', $approval->id)
            ->assertJsonPath('data.status', 'pending');
    }

    public function test_can_approve_request(): void
    {
        Event::fake([ExperimentTransitioned::class]);
        $this->actingAsApiUser();

        $approval = $this->createApproval();

        $response = $this->postJson("/api/v1/approvals/{$approval->id}/approve", [
            'notes' => 'Looks good',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approval->id,
            'status' => 'approved',
        ]);
    }

    public function test_can_reject_request(): void
    {
        $this->actingAsApiUser();
        $approval = $this->createApproval();

        $response = $this->postJson("/api/v1/approvals/{$approval->id}/reject", [
            'reason' => 'Content not appropriate',
            'notes' => 'Please revise',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approval->id,
            'status' => 'rejected',
        ]);
    }

    public function test_reject_requires_reason(): void
    {
        $this->actingAsApiUser();
        $approval = $this->createApproval();

        $response = $this->postJson("/api/v1/approvals/{$approval->id}/reject", []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_unauthenticated_cannot_list_approvals(): void
    {
        $response = $this->getJson('/api/v1/approvals');

        $response->assertStatus(401);
    }
}
