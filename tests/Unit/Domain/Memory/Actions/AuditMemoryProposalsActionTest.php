<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\AuditMemoryProposalsAction;
use App\Domain\Memory\Actions\RejectMemoryProposalAction;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditMemoryProposalsActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    private AuditMemoryProposalsAction $auditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $this->auditor = new AuditMemoryProposalsAction(new RejectMemoryProposalAction);

        config([
            'memory.proposal_workflow.min_content_length' => 30,
            'memory.proposal_workflow.min_confidence' => 0.3,
            'memory.proposal_workflow.auto_approve_threshold' => 0.85,
        ]);
    }

    public function test_auto_approves_high_confidence_proposal_to_target_tier(): void
    {
        $memory = $this->createProposal([
            'content' => str_repeat('A', 60),
            'confidence' => 0.95,
            'metadata' => ['target_tier' => 'successes'],
        ]);

        $result = $this->auditor->execute(teamId: $this->team->id);

        $this->assertSame(1, $result['approved']);
        $this->assertSame(0, $result['rejected']);
        $this->assertSame(0, $result['queued']);

        $memory->refresh();
        $this->assertSame(MemoryTier::Successes, $memory->tier);
        $this->assertSame('approved', $memory->proposal_status);
        $this->assertNotNull($memory->reviewed_at);
        $this->assertSame('system:auditor', $memory->reviewed_by);
    }

    public function test_rejects_short_content(): void
    {
        $memory = $this->createProposal([
            'content' => 'short',
            'confidence' => 0.95,
        ]);

        $result = $this->auditor->execute(teamId: $this->team->id);

        $this->assertSame(0, $result['approved']);
        $this->assertSame(1, $result['rejected']);

        $memory->refresh();
        $this->assertSame(MemoryTier::Proposed, $memory->tier);
        $this->assertSame('rejected', $memory->proposal_status);
        $this->assertStringContainsString('shorter', (string) $memory->rejection_reason);
    }

    public function test_rejects_low_confidence(): void
    {
        $memory = $this->createProposal([
            'content' => str_repeat('A', 60),
            'confidence' => 0.1,
        ]);

        $result = $this->auditor->execute(teamId: $this->team->id);

        $this->assertSame(1, $result['rejected']);

        $memory->refresh();
        $this->assertSame('rejected', $memory->proposal_status);
        $this->assertStringContainsString('Confidence', (string) $memory->rejection_reason);
    }

    public function test_leaves_borderline_proposal_pending(): void
    {
        $memory = $this->createProposal([
            'content' => str_repeat('A', 60),
            'confidence' => 0.5,
        ]);

        $result = $this->auditor->execute(teamId: $this->team->id);

        $this->assertSame(0, $result['approved']);
        $this->assertSame(0, $result['rejected']);
        $this->assertSame(1, $result['queued']);

        $memory->refresh();
        $this->assertNull($memory->proposal_status);
        $this->assertSame(MemoryTier::Proposed, $memory->tier);
    }

    public function test_skips_already_decided(): void
    {
        $approved = $this->createProposal([
            'content' => str_repeat('A', 60),
            'confidence' => 0.95,
            'proposal_status' => 'approved',
        ]);

        $rejected = $this->createProposal([
            'content' => str_repeat('B', 60),
            'confidence' => 0.95,
            'proposal_status' => 'rejected',
        ]);

        $result = $this->auditor->execute(teamId: $this->team->id);

        $this->assertSame(0, $result['approved']);
        $this->assertSame(0, $result['rejected']);
        $this->assertSame(0, $result['queued']);

        $approved->refresh();
        $rejected->refresh();
        $this->assertSame('approved', $approved->proposal_status);
        $this->assertSame('rejected', $rejected->proposal_status);
    }

    public function test_falls_back_to_canonical_when_target_tier_missing(): void
    {
        $memory = $this->createProposal([
            'content' => str_repeat('A', 60),
            'confidence' => 0.95,
            'metadata' => [], // no target_tier
        ]);

        $this->auditor->execute(teamId: $this->team->id);

        $memory->refresh();
        $this->assertSame(MemoryTier::Canonical, $memory->tier);
    }

    public function test_falls_back_to_canonical_when_target_tier_invalid(): void
    {
        $memory = $this->createProposal([
            'content' => str_repeat('A', 60),
            'confidence' => 0.95,
            'metadata' => ['target_tier' => 'not-a-real-tier'],
        ]);

        $this->auditor->execute(teamId: $this->team->id);

        $memory->refresh();
        $this->assertSame(MemoryTier::Canonical, $memory->tier);
    }

    public function test_returns_zeros_when_pool_is_empty(): void
    {
        $result = $this->auditor->execute(teamId: $this->team->id);

        $this->assertSame(['approved' => 0, 'rejected' => 0, 'queued' => 0], $result);
    }

    public function test_scopes_to_team_when_team_id_provided(): void
    {
        $otherTeam = Team::factory()->create();
        $otherAgent = Agent::factory()->create(['team_id' => $otherTeam->id]);

        $mine = $this->createProposal([
            'content' => str_repeat('A', 60),
            'confidence' => 0.95,
            'metadata' => ['target_tier' => 'successes'],
        ]);

        Memory::create([
            'team_id' => $otherTeam->id,
            'agent_id' => $otherAgent->id,
            'content' => str_repeat('B', 60),
            'source_type' => 'experiment',
            'tier' => MemoryTier::Proposed->value,
            'confidence' => 0.95,
            'proposed_by' => 'system:success_extractor',
            'metadata' => ['target_tier' => 'successes'],
        ]);

        $result = $this->auditor->execute(teamId: $this->team->id);

        $this->assertSame(1, $result['approved']);
        $mine->refresh();
        $this->assertSame('approved', $mine->proposal_status);

        // Other team's proposal untouched
        $otherStillPending = Memory::withoutGlobalScopes()
            ->where('team_id', $otherTeam->id)
            ->first();
        $this->assertNull($otherStillPending->proposal_status);
    }

    private function createProposal(array $overrides): Memory
    {
        return Memory::create(array_merge([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => str_repeat('X', 60),
            'source_type' => 'experiment',
            'tier' => MemoryTier::Proposed->value,
            'confidence' => 0.5,
            'proposed_by' => 'system:success_extractor',
            'metadata' => [],
        ], $overrides));
    }
}
