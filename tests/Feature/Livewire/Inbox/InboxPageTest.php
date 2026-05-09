<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Inbox;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Livewire\Inbox\InboxPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InboxPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Inbox Test',
            'slug' => 'inbox-test',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);
    }

    private function makeApproval(array $overrides = []): ApprovalRequest
    {
        return ApprovalRequest::create(array_merge([
            'team_id' => $this->team->id,
            'status' => ApprovalStatus::Pending,
            'context' => ['summary' => 'Test approval'],
        ], $overrides));
    }

    private function makeProposal(): OutboundProposal
    {
        return OutboundProposal::create([
            'team_id' => $this->team->id,
            'channel' => OutboundChannel::Email,
            'target' => ['address' => 'a@example.com'],
            'content' => ['subject' => 'Hi', 'body' => 'Hello'],
            'risk_score' => 0.1,
            'status' => OutboundProposalStatus::PendingApproval,
        ]);
    }

    public function test_it_lists_pending_approvals_and_proposals(): void
    {
        $this->makeApproval();
        $this->makeProposal();

        Livewire::test(InboxPage::class)
            ->assertSet('filter', 'all')
            ->tap(function ($component) {
                $items = $component->get('items') ?? null;
                // Use rendered counts assertion instead — Livewire computed prop
                $component->assertSeeHtml('data-test="inbox-list"');
            })
            ->assertSee('Approval request')
            ->assertSee('email →');
    }

    public function test_it_filters_by_kind(): void
    {
        $this->makeApproval();
        $this->makeProposal();

        Livewire::test(InboxPage::class)
            ->call('setFilter', 'proposals')
            ->assertSet('filter', 'proposals')
            ->assertSee('email →')
            ->assertDontSee('Approval request');
    }

    public function test_it_marks_sla_red_when_past_deadline(): void
    {
        $this->makeApproval([
            'sla_deadline' => now()->subHour(),
            'workflow_node_id' => null,
        ]);

        Livewire::test(InboxPage::class)
            ->assertSeeHtml('data-test="inbox-sla-red"');
    }

    public function test_it_isolates_other_teams_items(): void
    {
        $this->makeApproval();

        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-inbox-test',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);

        ApprovalRequest::create([
            'team_id' => $otherTeam->id,
            'status' => ApprovalStatus::Pending,
            'context' => ['summary' => 'Hidden approval from other team'],
        ]);

        Livewire::test(InboxPage::class)
            ->assertDontSee('Hidden approval from other team');
    }

    public function test_quick_approve_no_ops_when_already_resolved(): void
    {
        $approval = $this->makeApproval(['status' => ApprovalStatus::Approved]);

        Livewire::test(InboxPage::class)
            ->call('quickApprove', $approval->id);

        $this->assertSame(ApprovalStatus::Approved, $approval->fresh()->status);
    }

    public function test_renders_empty_state_when_nothing_pending(): void
    {
        Livewire::test(InboxPage::class)
            ->assertSeeHtml('data-test="inbox-empty"')
            ->assertSee('Nothing pending');
    }
}
