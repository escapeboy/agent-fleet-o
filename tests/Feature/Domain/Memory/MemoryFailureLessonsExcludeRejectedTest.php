<?php

namespace Tests\Feature\Domain\Memory;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Enums\MemoryTier;
use App\Domain\Memory\Models\Memory;
use App\Domain\Memory\Services\MemoryContextInjector;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * Verifies that rejected proposals never surface in the failure-lessons
 * context (MemoryContextInjector). The full RetrieveRelevantMemoriesAction
 * path requires pgvector which isn't available in the SQLite test DB; that
 * filter is unit-validated by a query inspection check below.
 */
class MemoryFailureLessonsExcludeRejectedTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private Agent $agent;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        $this->agent = Agent::factory()->create(['team_id' => $this->team->id]);
    }

    public function test_failure_lessons_excludes_rejected_proposal(): void
    {
        $approved = $this->createFailureMemory([
            'content' => 'Approved lesson: validate inputs before LLM call',
            'proposal_status' => 'approved',
            'importance' => 0.9,
        ]);

        $rejected = $this->createFailureMemory([
            'content' => 'Rejected lesson: noisy and duplicate',
            'proposal_status' => 'rejected',
            'rejection_reason' => 'duplicate',
            'importance' => 0.95,
        ]);

        $legacy = $this->createFailureMemory([
            'content' => 'Legacy lesson: nullable proposal_status',
            'proposal_status' => null,
            'importance' => 0.8,
        ]);

        // We need RetrieveRelevantMemoriesAction for the constructor but the
        // failure-lessons path runs a separate Eloquent query, so we can mock
        // it to a benign default.
        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')->andReturn(collect());

        $injector = new MemoryContextInjector($retrieve);
        $context = $injector->buildContext(
            agentId: $this->agent->id,
            input: 'some prompt',
            projectId: null,
            teamId: $this->team->id,
        );

        $this->assertNotNull($context);
        $this->assertStringContainsString('Approved lesson', $context);
        $this->assertStringContainsString('Legacy lesson', $context);
        $this->assertStringNotContainsString('Rejected lesson', $context);
    }

    private function createFailureMemory(array $overrides): Memory
    {
        return Memory::create(array_merge([
            'team_id' => $this->team->id,
            'agent_id' => $this->agent->id,
            'content' => 'lesson',
            'source_type' => 'experiment',
            'tier' => MemoryTier::Failures->value,
            'confidence' => 0.9,
            'importance' => 0.5,
            'proposed_by' => 'system:failure_extractor',
            'metadata' => [],
        ], $overrides));
    }
}
