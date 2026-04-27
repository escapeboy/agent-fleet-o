<?php

namespace Tests\Feature\Domain\Approval;

use App\Domain\Approval\Actions\ApproveActionProposalAction;
use App\Domain\Approval\Actions\CreateActionProposalAction;
use App\Domain\Approval\Actions\ExpireStaleActionProposalsAction;
use App\Domain\Approval\Actions\RejectActionProposalAction;
use App\Domain\Approval\Enums\ActionProposalStatus;
use App\Domain\Approval\Models\ActionProposal;
use App\Domain\Assistant\Models\AssistantConversation;
use App\Domain\Assistant\Models\AssistantMessage;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class ActionProposalTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'T '.bin2hex(random_bytes(3)),
            'slug' => 't-'.bin2hex(random_bytes(3)),
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
    }

    public function test_create_proposal_persists_with_payload_and_pending_status(): void
    {
        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Delete agent X',
            payload: ['tool' => 'agent_delete', 'args' => ['agent_id' => 'abc']],
            userId: $this->user->id,
        );

        $this->assertDatabaseHas('action_proposals', [
            'id' => $proposal->id,
            'team_id' => $this->team->id,
            'target_type' => 'tool_call',
            'status' => ActionProposalStatus::Pending->value,
        ]);
        $this->assertSame('agent_delete', $proposal->payload['tool']);
        $this->assertSame([], $proposal->lineage);
    }

    public function test_create_proposal_captures_lineage_from_conversation(): void
    {
        $conversation = AssistantConversation::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'title' => 'Test',
        ]);
        $now = now();
        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Please delete agent X because it is broken.',
            'created_at' => $now->copy()->subSeconds(3),
        ]);
        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => 'Sure, I will delete agent X now.',
            'created_at' => $now->copy()->subSeconds(2),
        ]);
        AssistantMessage::create([
            'conversation_id' => $conversation->id,
            'role' => 'tool_call',
            'content' => 'agent_delete({"agent_id":"abc"})',
            'created_at' => $now->copy()->subSeconds(1),
        ]);

        $proposal = app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Delete agent X',
            payload: ['tool' => 'agent_delete'],
            userId: $this->user->id,
            conversation: $conversation,
        );

        $this->assertCount(3, $proposal->lineage);
        $this->assertSame('user', $proposal->lineage[0]['role']);
        $this->assertSame('tool_call', $proposal->lineage[2]['role']);
        $this->assertStringContainsString('Please delete agent X', $proposal->lineage[0]['snippet']);
    }

    public function test_approve_proposal_marks_status_and_decision_metadata(): void
    {
        $proposal = $this->makeProposal();

        app(ApproveActionProposalAction::class)->execute($proposal, $this->user, 'looks fine');

        $proposal->refresh();
        $this->assertSame(ActionProposalStatus::Approved, $proposal->status);
        $this->assertSame($this->user->id, $proposal->decided_by_user_id);
        $this->assertNotNull($proposal->decided_at);
        $this->assertSame('looks fine', $proposal->decision_reason);
    }

    public function test_approve_rejects_when_user_belongs_to_other_team(): void
    {
        $proposal = $this->makeProposal();

        $otherUser = User::factory()->create(['current_team_id' => null]);

        $this->expectException(RuntimeException::class);
        app(ApproveActionProposalAction::class)->execute($proposal, $otherUser);
    }

    public function test_approve_rejects_when_proposal_already_decided(): void
    {
        $proposal = $this->makeProposal();
        $proposal->update(['status' => ActionProposalStatus::Approved]);

        $this->expectException(RuntimeException::class);
        app(ApproveActionProposalAction::class)->execute($proposal->fresh(), $this->user);
    }

    public function test_reject_requires_reason(): void
    {
        $proposal = $this->makeProposal();

        $this->expectException(RuntimeException::class);
        app(RejectActionProposalAction::class)->execute($proposal, $this->user, '   ');
    }

    public function test_reject_marks_status_and_decision_metadata(): void
    {
        $proposal = $this->makeProposal();

        app(RejectActionProposalAction::class)->execute($proposal, $this->user, 'too risky');

        $proposal->refresh();
        $this->assertSame(ActionProposalStatus::Rejected, $proposal->status);
        $this->assertSame('too risky', $proposal->decision_reason);
    }

    public function test_expire_stale_flips_pending_with_past_expiry_to_expired(): void
    {
        $stale = $this->makeProposal();
        $stale->update(['expires_at' => now()->subHour()]);

        $fresh = $this->makeProposal();
        $fresh->update(['expires_at' => now()->addHour()]);

        $unboundedExpiry = $this->makeProposal();
        $unboundedExpiry->update(['expires_at' => null]);

        $count = app(ExpireStaleActionProposalsAction::class)->execute();

        $this->assertSame(1, $count);
        $this->assertSame(ActionProposalStatus::Expired->value, $stale->fresh()->status->value);
        $this->assertSame(ActionProposalStatus::Pending->value, $fresh->fresh()->status->value);
        $this->assertSame(ActionProposalStatus::Pending->value, $unboundedExpiry->fresh()->status->value);
    }

    private function makeProposal(): ActionProposal
    {
        return app(CreateActionProposalAction::class)->execute(
            teamId: $this->team->id,
            targetType: 'tool_call',
            targetId: null,
            summary: 'Test action',
            payload: ['tool' => 'noop'],
            userId: $this->user->id,
        );
    }
}
