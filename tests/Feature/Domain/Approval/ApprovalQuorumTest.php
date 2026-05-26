<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ApprovalQuorumTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $userA;

    private User $userB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->userA = User::factory()->create();
        $this->userB = User::factory()->create();
    }

    private function credentialApproval(int $required): ApprovalRequest
    {
        $credential = Credential::factory()->create([
            'team_id' => $this->team->id,
            'status' => CredentialStatus::PendingReview,
        ]);

        return ApprovalRequest::factory()->create([
            'team_id' => $this->team->id,
            'credential_id' => $credential->id,
            'outbound_proposal_id' => null,
            'status' => ApprovalStatus::Pending,
            'required_approvals' => $required,
        ]);
    }

    public function test_single_approval_default_flips_immediately(): void
    {
        $approval = $this->credentialApproval(1);

        app(ApproveAction::class)->execute($approval, $this->userA->id);

        $this->assertEquals(ApprovalStatus::Approved, $approval->fresh()->status);
    }

    public function test_first_of_two_approvals_stays_pending(): void
    {
        $approval = $this->credentialApproval(2);

        app(ApproveAction::class)->execute($approval, $this->userA->id);

        $fresh = $approval->fresh();
        $this->assertEquals(ApprovalStatus::Pending, $fresh->status);
        $this->assertEquals(1, $fresh->approveVoteCount());
    }

    public function test_two_distinct_approvers_reach_quorum(): void
    {
        $approval = $this->credentialApproval(2);

        app(ApproveAction::class)->execute($approval, $this->userA->id);
        app(ApproveAction::class)->execute($approval->fresh(), $this->userB->id);

        $fresh = $approval->fresh();
        $this->assertEquals(ApprovalStatus::Approved, $fresh->status);
        $this->assertEquals(CredentialStatus::Active, $fresh->credential->status);
    }

    public function test_same_user_voting_twice_does_not_reach_quorum(): void
    {
        $approval = $this->credentialApproval(2);

        app(ApproveAction::class)->execute($approval, $this->userA->id);
        app(ApproveAction::class)->execute($approval->fresh(), $this->userA->id);

        $fresh = $approval->fresh();
        $this->assertEquals(ApprovalStatus::Pending, $fresh->status);
        $this->assertEquals(1, $fresh->approveVoteCount());
    }

    public function test_single_rejection_vetoes_regardless_of_quorum(): void
    {
        $approval = $this->credentialApproval(3);

        app(ApproveAction::class)->execute($approval, $this->userA->id);
        app(RejectAction::class)->execute($approval->fresh(), $this->userB->id, 'not safe');

        $fresh = $approval->fresh();
        $this->assertEquals(ApprovalStatus::Rejected, $fresh->status);
        $this->assertEquals(1, $fresh->votes()->where('decision', 'reject')->count());
    }

    public function test_cannot_approve_already_resolved_request(): void
    {
        $approval = $this->credentialApproval(1);
        app(ApproveAction::class)->execute($approval, $this->userA->id);

        $this->expectException(InvalidArgumentException::class);
        app(ApproveAction::class)->execute($approval->fresh(), $this->userB->id);
    }
}
