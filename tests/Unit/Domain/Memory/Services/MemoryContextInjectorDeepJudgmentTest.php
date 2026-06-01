<?php

namespace Tests\Unit\Domain\Memory\Services;

use App\Domain\Memory\Actions\RetrieveRelevantMemoriesAction;
use App\Domain\Memory\Services\MemoryContextInjector;
use App\Domain\Memory\Services\MemoryRelevanceJudge;
use App\Domain\Shared\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Mockery;
use Tests\TestCase;

class MemoryContextInjectorDeepJudgmentTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    protected function setUp(): void
    {
        parent::setUp();
        $this->team = Team::factory()->create();
        config(['memory.enabled' => true]);
        config(['memory.unified_search.enabled' => false]);
    }

    private function memories(): Collection
    {
        return new Collection([
            (object) [
                'content' => 'Relevant: use feature flags',
                'source_type' => 'execution',
                'effective_importance' => 0.7,
                'created_at' => now()->subDay(),
                'retrieval_count' => 1,
            ],
            (object) [
                'content' => 'Irrelevant: water the plant',
                'source_type' => 'execution',
                'effective_importance' => 0.5,
                'created_at' => now()->subWeek(),
                'retrieval_count' => 0,
            ],
        ]);
    }

    private function retrieveReturning(Collection $memories): RetrieveRelevantMemoriesAction
    {
        $retrieve = Mockery::mock(RetrieveRelevantMemoriesAction::class);
        $retrieve->shouldReceive('execute')->andReturn($memories);

        return $retrieve;
    }

    public function test_deep_judgment_filters_out_low_relevance_memory(): void
    {
        config(['memory.deep_judgment.enabled' => true]);
        config(['memory.deep_judgment.min_candidates' => 1]);

        $judge = Mockery::mock(MemoryRelevanceJudge::class);
        // Keep only the first candidate (positional id "0").
        $judge->shouldReceive('judge')->once()->andReturn(['0']);

        $injector = new MemoryContextInjector($this->retrieveReturning($this->memories()), null, $judge);

        $context = $injector->buildContext('agent-1', 'how to ship risky changes', null, $this->team->id, 'user-1');

        $this->assertStringContainsString('use feature flags', $context);
        $this->assertStringNotContainsString('water the plant', $context);
    }

    public function test_disabled_skips_judge_and_keeps_all(): void
    {
        config(['memory.deep_judgment.enabled' => false]);

        $judge = Mockery::mock(MemoryRelevanceJudge::class);
        $judge->shouldNotReceive('judge');

        $injector = new MemoryContextInjector($this->retrieveReturning($this->memories()), null, $judge);

        $context = $injector->buildContext('agent-1', 'q', null, $this->team->id, 'user-1');

        $this->assertStringContainsString('use feature flags', $context);
        $this->assertStringContainsString('water the plant', $context);
    }

    public function test_below_min_candidates_skips_judge(): void
    {
        config(['memory.deep_judgment.enabled' => true]);
        config(['memory.deep_judgment.min_candidates' => 5]);

        $judge = Mockery::mock(MemoryRelevanceJudge::class);
        $judge->shouldNotReceive('judge');

        $injector = new MemoryContextInjector($this->retrieveReturning($this->memories()), null, $judge);

        $context = $injector->buildContext('agent-1', 'q', null, $this->team->id, 'user-1');

        $this->assertStringContainsString('water the plant', $context);
    }

    public function test_null_user_skips_judge(): void
    {
        config(['memory.deep_judgment.enabled' => true]);
        config(['memory.deep_judgment.min_candidates' => 1]);

        $judge = Mockery::mock(MemoryRelevanceJudge::class);
        $judge->shouldNotReceive('judge');

        $injector = new MemoryContextInjector($this->retrieveReturning($this->memories()), null, $judge);

        $context = $injector->buildContext('agent-1', 'q', null, $this->team->id, null);

        $this->assertStringContainsString('water the plant', $context);
    }
}
