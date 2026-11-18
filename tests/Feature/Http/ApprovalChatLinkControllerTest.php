<?php

namespace Tests\Feature\Http;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Services\ApprovalLinkSigner;
use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApprovalChatLinkControllerTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['terms.current_version' => 0]);

        $this->team = Team::factory()->create(['settings' => []]);
        $this->member = $this->userWithRole($this->team, 'member');
    }

    private function userWithRole(Team $team, string $role): User
    {
        $user = User::factory()->create(['current_team_id' => $team->id]);
        $user->teams()->attach($team->id, ['role' => $role]);

        return $user;
    }

    private function credentialApproval(array $overrides = []): ApprovalRequest
    {
        $credential = Credential::factory()->create([
            'team_id' => $this->team->id,
            'status' => CredentialStatus::PendingReview,
        ]);

        return ApprovalRequest::factory()->create(array_merge([
            'team_id' => $this->team->id,
            'credential_id' => $credential->id,
            'outbound_proposal_id' => null,
            'status' => ApprovalStatus::Pending,
            'context' => ['experiment_title' => 'Rotate API key'],
        ], $overrides));
    }

    private function link(ApprovalRequest $approval, string $decision): string
    {
        return app(ApprovalLinkSigner::class)->url($approval, $decision);
    }

    public function test_signed_get_shows_confirmation_and_leaves_pending(): void
    {
        $approval = $this->credentialApproval();

        $this->actingAs($this->member)
            ->get($this->link($approval, 'approve'))
            ->assertOk()
            ->assertSee('Rotate API key')
            ->assertSee('<form', false);

        $this->assertEquals(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $approval = $this->credentialApproval();

        $this->get($this->link($approval, 'approve'))
            ->assertRedirect(route('login'));
    }

    public function test_tampered_signature_is_forbidden(): void
    {
        $approval = $this->credentialApproval();
        $url = $this->link($approval, 'approve');
        $tampered = str_replace('/chat/approve', '/chat/reject', $url);

        $this->actingAs($this->member)->get($tampered)->assertForbidden();
    }

    public function test_expired_signature_is_forbidden(): void
    {
        $approval = $this->credentialApproval(['expires_at' => now()->addHour()]);
        $url = $this->link($approval, 'approve');

        $this->travel(2)->hours();

        $this->actingAs($this->member)->get($url)->assertForbidden();
    }

    public function test_signer_rejects_unknown_decision(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->link($this->credentialApproval(), 'delete');
    }

    public function test_member_post_approve_approves_credential_review(): void
    {
        $approval = $this->credentialApproval();

        $this->actingAs($this->member)
            ->post($this->link($approval, 'approve'))
            ->assertOk();

        $fresh = $approval->fresh();
        $this->assertEquals(ApprovalStatus::Approved, $fresh->status);
        $this->assertEquals($this->member->id, $fresh->reviewed_by);
        $credential = Credential::withoutGlobalScopes()->findOrFail($fresh->credential_id);
        $this->assertEquals(CredentialStatus::Active, $credential->status);
    }

    public function test_member_with_other_active_team_can_still_decide(): void
    {
        $approval = $this->credentialApproval();
        $otherTeam = Team::factory()->create();
        $this->member->teams()->attach($otherTeam->id, ['role' => 'owner']);
        $this->member->update(['current_team_id' => $otherTeam->id]);

        $this->actingAs($this->member)
            ->post($this->link($approval, 'approve'))
            ->assertOk();

        $this->assertEquals(ApprovalStatus::Approved, $approval->fresh()->status);
    }

    public function test_member_post_reject_rejects_with_reason(): void
    {
        $approval = $this->credentialApproval();

        $this->actingAs($this->member)
            ->post($this->link($approval, 'reject'))
            ->assertOk();

        $fresh = $approval->fresh();
        $this->assertEquals(ApprovalStatus::Rejected, $fresh->status);
        $this->assertEquals($this->member->id, $fresh->reviewed_by);
        $this->assertNotEmpty($fresh->rejection_reason);
    }

    public function test_user_outside_team_is_forbidden(): void
    {
        $approval = $this->credentialApproval();
        $outsider = $this->userWithRole(Team::factory()->create(), 'owner');

        $this->actingAs($outsider)
            ->post($this->link($approval, 'approve'))
            ->assertForbidden();

        $this->assertEquals(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_viewer_is_forbidden(): void
    {
        $approval = $this->credentialApproval();
        $viewer = $this->userWithRole($this->team, 'viewer');

        $this->actingAs($viewer)
            ->post($this->link($approval, 'approve'))
            ->assertForbidden();

        $this->assertEquals(ApprovalStatus::Pending, $approval->fresh()->status);
    }

    public function test_already_decided_approval_shows_message(): void
    {
        $approval = $this->credentialApproval();
        $url = $this->link($approval, 'reject');
        $approval->update(['status' => ApprovalStatus::Approved, 'reviewed_at' => now()]);

        $this->actingAs($this->member)
            ->post($url)
            ->assertOk()
            ->assertSee('already decided');

        $this->assertEquals(ApprovalStatus::Approved, $approval->fresh()->status);
    }
}
