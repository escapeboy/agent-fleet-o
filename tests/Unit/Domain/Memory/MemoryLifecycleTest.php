<?php

namespace Tests\Unit\Domain\Memory;

use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Memory\Enums\WriteGateDecision;
use App\Domain\Memory\Models\Memory;
use Tests\TestCase;

class MemoryLifecycleTest extends TestCase
{
    // --- WriteGateDecision Enum ---

    public function test_write_gate_decision_has_three_values(): void
    {
        $this->assertCount(3, WriteGateDecision::cases());
        $this->assertEquals('add', WriteGateDecision::Add->value);
        $this->assertEquals('update', WriteGateDecision::Update->value);
        $this->assertEquals('skip', WriteGateDecision::Skip->value);
    }

    // --- MemoryVisibility Enum ---

    public function test_memory_visibility_has_three_values(): void
    {
        $this->assertCount(3, MemoryVisibility::cases());
        $this->assertEquals('private', MemoryVisibility::Private->value);
        $this->assertEquals('project', MemoryVisibility::Project->value);
        $this->assertEquals('team', MemoryVisibility::Team->value);
    }

    // --- Memory Model ---

    public function test_memory_model_casts_visibility(): void
    {
        $memory = new Memory;
        $memory->visibility = MemoryVisibility::Project;

        $this->assertInstanceOf(MemoryVisibility::class, $memory->visibility);
        $this->assertEquals(MemoryVisibility::Project, $memory->visibility);
    }

    public function test_memory_model_casts_retrieval_count_to_integer(): void
    {
        $memory = new Memory(['retrieval_count' => '5']);

        $this->assertIsInt($memory->retrieval_count);
        $this->assertEquals(5, $memory->retrieval_count);
    }

    public function test_effective_importance_with_zero_retrievals(): void
    {
        $memory = new Memory(['importance' => 0.5, 'retrieval_count' => 0]);

        // ln(1+0)*0.15 = 0, so effective = 0.5
        $this->assertEqualsWithDelta(0.5, $memory->effective_importance, 0.01);
    }

    public function test_effective_importance_increases_with_retrievals(): void
    {
        $memory = new Memory(['importance' => 0.5, 'retrieval_count' => 5]);

        // ln(1+5)*0.15 = ln(6)*0.15 ≈ 1.79*0.15 ≈ 0.269 → 0.5 + 0.269 = 0.769
        $this->assertGreaterThan(0.5, $memory->effective_importance);
        $this->assertEqualsWithDelta(0.769, $memory->effective_importance, 0.02);
    }

    public function test_effective_importance_caps_at_one(): void
    {
        $memory = new Memory(['importance' => 0.9, 'retrieval_count' => 100]);

        // Should cap at 1.0 regardless of how high retrieval_count goes
        $this->assertEqualsWithDelta(1.0, $memory->effective_importance, 0.001);
    }

    public function test_effective_importance_handles_null_values(): void
    {
        $memory = new Memory;

        // Defaults: importance=null→0.5, retrieval_count=null→0
        $this->assertEqualsWithDelta(0.5, $memory->effective_importance, 0.01);
    }

    public function test_new_fillable_fields_are_present(): void
    {
        $memory = new Memory;

        $this->assertTrue(in_array('retrieval_count', $memory->getFillable()));
        $this->assertTrue(in_array('visibility', $memory->getFillable()));
        $this->assertTrue(in_array('content_hash', $memory->getFillable()));
    }

    // --- Config ---

    public function test_write_gate_config_defaults(): void
    {
        $this->assertTrue(config('memory.write_gate.enabled'));
        $this->assertEqualsWithDelta(0.95, config('memory.write_gate.skip_threshold'), 0.001);
        $this->assertEqualsWithDelta(0.85, config('memory.write_gate.update_threshold'), 0.001);
        $this->assertTrue(config('memory.write_gate.hash_dedup'));
    }

    public function test_consolidation_config_defaults(): void
    {
        $this->assertTrue(config('memory.consolidation.enabled'));
        $this->assertEquals(50, config('memory.consolidation.min_memories_per_agent'));
        $this->assertEquals(3, config('memory.consolidation.min_cluster_size'));
        $this->assertEqualsWithDelta(0.85, config('memory.consolidation.similarity_threshold'), 0.001);
        $this->assertEquals('claude-haiku-4-5', config('memory.consolidation.model'));
    }

    public function test_pruning_config_defaults(): void
    {
        $this->assertEqualsWithDelta(0.05, config('memory.pruning.score_threshold'), 0.001);
        $this->assertEquals(365, config('memory.pruning.max_ttl_days'));
        $this->assertEqualsWithDelta(0.8, config('memory.pruning.protect_importance_above'), 0.001);
        $this->assertEquals(10, config('memory.pruning.protect_retrieval_above'));
    }

    public function test_unified_search_config_defaults(): void
    {
        $this->assertTrue(config('memory.unified_search.enabled'));
        $this->assertEqualsWithDelta(2.0, config('memory.unified_search.kg_weight'), 0.001);
        $this->assertEqualsWithDelta(1.0, config('memory.unified_search.vector_weight'), 0.001);
        $this->assertEqualsWithDelta(0.5, config('memory.unified_search.keyword_weight'), 0.001);
        $this->assertEquals(60, config('memory.unified_search.rrf_k'));
    }

    public function test_visibility_config_defaults(): void
    {
        $this->assertEquals(3, config('memory.visibility.auto_promote_retrievals'));
        $this->assertEqualsWithDelta(0.7, config('memory.visibility.auto_promote_min_importance'), 0.001);
    }
}
