<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Inbox;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Credential\Models\Credential;
use App\Domain\Inbox\Models\InboxQueue;
use App\Domain\Outbound\Enums\OutboundChannel;
use App\Domain\Outbound\Enums\OutboundProposalStatus;
use App\Domain\Outbound\Models\OutboundProposal;
use App\Domain\Shared\Models\Team;
use App\Livewire\Inbox\InboxPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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

    private function makeCredentialApproval(): ApprovalRequest
    {
        $name = 'Test '.uniqid();
        $credential = Credential::create([
            'team_id' => $this->team->id,
            'name' => $name,
            'slug' => Str::slug($name),
            'credential_type' => 'api_token',
            'status' => 'pending_review',
            'secret_data' => ['api_key' => 'test-key'],
        ]);

        return ApprovalRequest::create([
            'team_id' => $this->team->id,
            'credential_id' => $credential->id,
            'status' => ApprovalStatus::Pending,
            'context' => ['type' => 'credential_review'],
        ]);
    }

    public function test_bulk_reject_processes_multiple_selected_items(): void
    {
        // Use credential-review approvals — these have an early-return code path
        // in RejectAction that doesn't require an experiment.
        $a1 = $this->makeCredentialApproval();
        $a2 = $this->makeCredentialApproval();
        $a3 = $this->makeCredentialApproval();

        Livewire::test(InboxPage::class)
            ->call('toggleSelection', $a1->id)
            ->call('toggleSelection', $a2->id)
            ->call('bulkReject');

        $this->assertSame(ApprovalStatus::Rejected, $a1->fresh()->status);
        $this->assertSame(ApprovalStatus::Rejected, $a2->fresh()->status);
        $this->assertSame(ApprovalStatus::Pending, $a3->fresh()->status);
    }

    public function test_bulk_action_no_op_when_nothing_selected(): void
    {
        $a = $this->makeApproval();

        Livewire::test(InboxPage::class)
            ->call('bulkReject');

        $this->assertSame(ApprovalStatus::Pending, $a->fresh()->status);
    }

    public function test_toggle_selection_adds_and_removes(): void
    {
        $a = $this->makeApproval();

        Livewire::test(InboxPage::class)
            ->call('toggleSelection', $a->id)
            ->assertSet('selectedApprovalIds', [$a->id])
            ->call('toggleSelection', $a->id)
            ->assertSet('selectedApprovalIds', []);
    }

    public function test_can_create_custom_queue_and_filter_by_it(): void
    {
        $this->makeApproval();   // approval kind
        $this->makeProposal();   // proposal kind

        $component = Livewire::test(InboxPage::class)
            ->call('startCreateQueue')
            ->set('newQueueName', 'Approvals Only')
            ->set('newQueueKinds', ['approval'])
            ->call('createQueue');

        $queue = InboxQueue::where('team_id', $this->team->id)->first();
        $this->assertNotNull($queue);
        $this->assertSame(['approval'], $queue->allowedKinds());

        $component->assertSet('activeQueueId', $queue->id)
            ->assertSee('Approval request')
            ->assertDontSee('email →');
    }

    public function test_can_delete_queue(): void
    {
        $queue = InboxQueue::create([
            'team_id' => $this->team->id,
            'user_id' => $this->user->id,
            'name' => 'Tmp',
            'slug' => 'tmp-queue',
            'filter_rules' => ['kinds' => ['proposal']],
            'sort_order' => 0,
        ]);

        Livewire::test(InboxPage::class)
            ->call('deleteQueue', $queue->id);

        $this->assertNull(InboxQueue::find($queue->id));
    }

    public function test_other_team_queues_isolated(): void
    {
        $otherUser = User::factory()->create();
        $otherTeam = Team::create([
            'name' => 'Other',
            'slug' => 'other-queue-isolation',
            'owner_id' => $otherUser->id,
            'settings' => [],
        ]);
        InboxQueue::create([
            'team_id' => $otherTeam->id,
            'user_id' => $otherUser->id,
            'name' => 'Hidden Queue',
            'slug' => 'hidden-queue-other',
            'filter_rules' => ['kinds' => []],
            'sort_order' => 0,
        ]);

        Livewire::test(InboxPage::class)
            ->assertDontSee('Hidden Queue');
    }

    public function test_triage_sort_orders_by_score_desc(): void
    {
        // Create one low-priority and one high-priority approval. The high-priority
        // one is older (so it would sort *later* by created_at) but should appear
        // first under triage sort because of expired SLA.
        $low = $this->makeApproval();   // baseline, no SLA, recent

        $high = ApprovalRequest::create([
            'team_id' => $this->team->id,
            'status' => ApprovalStatus::Pending,
            'context' => ['type' => 'security_review'],
            'sla_deadline' => now()->subHours(2),
            'created_at' => now()->subDay(),
        ]);

        $component = Livewire::test(InboxPage::class)
            ->call('toggleTriageSort')
            ->assertSet('sortByTriage', true);

        $items = $component->viewData('items');
        $this->assertSame($high->id, $items[0]->id, 'High-priority item should be first under triage sort');
    }

    public function test_triage_sort_off_by_default(): void
    {
        Livewire::test(InboxPage::class)
            ->assertSet('sortByTriage', false);
    }

    public function test_renders_empty_state_when_nothing_pending(): void
    {
        Livewire::test(InboxPage::class)
            ->assertSeeHtml('data-test="inbox-empty"')
            ->assertSee('Nothing pending');
    }
}
