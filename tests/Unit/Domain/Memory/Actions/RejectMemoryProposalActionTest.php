<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\RejectMemoryProposalAction;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RejectMemoryProposalActionTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    private RejectMemoryProposalAction $action;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
        $this->action = new RejectMemoryProposalAction;
    }

    public function test_marks_pending_proposal_as_rejected_with_reason(): void
    {
        $memory = $this->createPendingProposal();

        $result = $this->action->execute($memory, 'duplicate of existing canonical fact', 'mcp:user@test');

        $this->assertTrue($result['rejected']);
        $this->assertNull($result['already']);

        $memory->refresh();
        $this->assertSame('rejected', $memory->proposal_status);
        $this->assertSame('duplicate of existing canonical fact', $memory->rejection_reason);
        $this->assertSame('mcp:user@test', $memory->reviewed_by);
        $this->assertNotNull($memory->reviewed_at);
    }

    public function test_truncates_reason_to_1000_characters(): void
    {
        $memory = $this->createPendingProposal();
        $longReason = str_repeat('A', 2000);

        $this->action->execute($memory, $longReason);

        $memory->refresh();
        $this->assertSame(1000, mb_strlen((string) $memory->rejection_reason));
    }

    public function test_is_idempotent_on_already_rejected(): void
    {
        $memory = $this->createPendingProposal();
        $this->action->execute($memory, 'first reason');

        $memory->refresh();
        $firstRejectedAt = $memory->reviewed_at;

        $result = $this->action->execute($memory, 'second reason');

        $this->assertFalse($result['rejected']);
        $this->assertSame('rejected', $result['already']);
        $memory->refresh();
        $this->assertSame('first reason', $memory->rejection_reason);
        $this->assertEquals($firstRejectedAt, $memory->reviewed_at);
    }

    public function test_does_not_reject_already_approved_memory(): void
    {
        $memory = $this->createPendingProposal();
        $memory->update(['proposal_status' => 'approved']);

        $result = $this->action->execute($memory, 'reason');

        $this->assertFalse($result['rejected']);
        $this->assertSame('approved', $result['already']);
        $memory->refresh();
        $this->assertSame('approved', $memory->proposal_status);
    }

    public function test_defaults_reviewed_by_to_system(): void
    {
        $memory = $this->createPendingProposal();

        $this->action->execute($memory, 'reason');

        $memory->refresh();
        $this->assertSame('system:reviewer', $memory->reviewed_by);
    }

    private function createPendingProposal(): Memory
    {
        return Memory::create([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'pending proposal content with enough length to pass min length check',
            'source_type' => 'experiment',
            'tier' => MemoryTier::Proposed->value,
            'confidence' => 0.7,
            'proposed_by' => 'system:success_extractor',
            'metadata' => [],
        ]);
    }
}
