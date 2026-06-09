<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use App\Livewire\Memory\MemoryProposalsPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Livewire\Livewire;
use Tests\TestCase;

class MemoryProposalsPageTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->team = Team::create([
            'name' => 'Proposals Test Team',
            'slug' => 'proposals-test-team',
            'owner_id' => $this->user->id,
            'settings' => [],
        ]);
        $this->user->update(['current_team_id' => $this->team->id]);
        $this->team->users()->attach($this->user, ['role' => 'owner']);
        $this->actingAs($this->user);

        $this->agent = Agent::factory()->for($this->team)->create();
    }

    private function makeProposal(array $overrides = []): Memory
    {
        return Memory::create(array_merge([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'proposed memory content with enough length to pass checks',
            'source_type' => 'experiment',
            'tier' => MemoryTier::Proposed->value,
            'confidence' => 0.7,
            'proposed_by' => 'system:success_extractor',
            'metadata' => [],
        ], $overrides));
    }

    public function test_queue_shows_only_pending_proposals(): void
    {
        $pending = $this->makeProposal(['content' => 'pending proposal awaiting review here']);
        $approved = $this->makeProposal(['proposal_status' => 'approved', 'content' => 'already approved memory item']);
        $working = $this->makeProposal(['tier' => MemoryTier::Working->value, 'content' => 'working tier memory item here']);

        Livewire::test(MemoryProposalsPage::class)
            ->assertSee('pending proposal awaiting review here')
            ->assertDontSee('already approved memory item')
            ->assertDontSee('working tier memory item here');
    }

    public function test_queue_does_not_leak_other_teams_proposals(): void
    {
        $other = Team::create([
            'name' => 'Other Team',
            'slug' => 'other-team',
            'owner_id' => User::factory()->create()->id,
            'settings' => [],
        ]);

        $mine = $this->makeProposal(['content' => 'my team proposal content here ok']);
        $theirs = Memory::create([
            'team_id' => $other->id,
            'content' => 'other team proposal content secret',
            'source_type' => 'experiment',
            'tier' => MemoryTier::Proposed->value,
            'confidence' => 0.7,
            'proposed_by' => 'system:other',
            'metadata' => [],
        ]);

        Livewire::test(MemoryProposalsPage::class)
            ->assertSee('my team proposal content here ok')
            ->assertDontSee('other team proposal content secret');
    }

    public function test_approve_promotes_proposal_to_curated_tier(): void
    {
        $memory = $this->makeProposal();

        Livewire::test(MemoryProposalsPage::class)
            ->set('approveTier.'.$memory->id, MemoryTier::Facts->value)
            ->call('approve', $memory->id);

        $memory->refresh();
        $this->assertSame('approved', $memory->proposal_status);
        $this->assertSame(MemoryTier::Facts->value, $memory->tier->value);
        $this->assertNotNull($memory->reviewed_at);
    }

    public function test_approve_defaults_to_canonical_when_no_tier_chosen(): void
    {
        $memory = $this->makeProposal();

        Livewire::test(MemoryProposalsPage::class)
            ->call('approve', $memory->id);

        $memory->refresh();
        $this->assertSame('approved', $memory->proposal_status);
        $this->assertSame(MemoryTier::Canonical->value, $memory->tier->value);
    }

    public function test_reject_records_reason_and_marks_rejected(): void
    {
        $memory = $this->makeProposal();

        Livewire::test(MemoryProposalsPage::class)
            ->call('startReject', $memory->id)
            ->set('rejectReason', 'duplicate of existing canonical fact')
            ->call('reject', $memory->id);

        $memory->refresh();
        $this->assertSame('rejected', $memory->proposal_status);
        $this->assertSame('duplicate of existing canonical fact', $memory->rejection_reason);
        $this->assertNotNull($memory->reviewed_at);
    }

    public function test_reject_requires_a_reason(): void
    {
        $memory = $this->makeProposal();

        Livewire::test(MemoryProposalsPage::class)
            ->set('rejectReason', '   ')
            ->call('reject', $memory->id);

        $memory->refresh();
        $this->assertNull($memory->proposal_status);
    }

    public function test_unauthorized_user_cannot_approve_or_reject(): void
    {
        // The community edition's edit-content gate is permissive
        // (fn () => true). Override it here to assert the component actually
        // routes both mutations through Gate::authorize('edit-content').
        Gate::define('edit-content', fn () => false);

        $memory = $this->makeProposal();

        Livewire::test(MemoryProposalsPage::class)
            ->call('approve', $memory->id)
            ->assertForbidden();

        Livewire::test(MemoryProposalsPage::class)
            ->set('rejectReason', 'spam')
            ->call('reject', $memory->id)
            ->assertForbidden();

        $memory->refresh();
        $this->assertNull($memory->proposal_status);
    }
}
