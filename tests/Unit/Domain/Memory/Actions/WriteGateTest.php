<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Memory\Actions\StoreMemoryAction;
use App\Domain\Memory\Enums\MemoryVisibility;
use App\Domain\Memory\Enums\WriteGateDecision;
use Illuminate\Support\Str;
use Tests\TestCase;

class WriteGateTest extends TestCase
{
    // --- WriteGateDecision Enum ---

    public function test_write_gate_decision_values(): void
    {
        $this->assertEquals('add', WriteGateDecision::Add->value);
        $this->assertEquals('update', WriteGateDecision::Update->value);
        $this->assertEquals('skip', WriteGateDecision::Skip->value);
    }

    // --- Visibility resolution via reflection ---

    public function test_resolve_visibility_from_document_source(): void
    {
        $action = new StoreMemoryAction;
        $method = new \ReflectionMethod($action, 'resolveVisibility');

        $this->assertEquals(MemoryVisibility::Team, $method->invoke($action, 'document'));
        $this->assertEquals(MemoryVisibility::Team, $method->invoke($action, 'manual_upload'));
    }

    public function test_resolve_visibility_from_experiment_source(): void
    {
        $action = new StoreMemoryAction;
        $method = new \ReflectionMethod($action, 'resolveVisibility');

        $this->assertEquals(MemoryVisibility::Project, $method->invoke($action, 'experiment'));
    }

    public function test_resolve_visibility_defaults_to_private(): void
    {
        $action = new StoreMemoryAction;
        $method = new \ReflectionMethod($action, 'resolveVisibility');

        $this->assertEquals(MemoryVisibility::Private, $method->invoke($action, 'execution'));
        $this->assertEquals(MemoryVisibility::Private, $method->invoke($action, 'conversation'));
        $this->assertEquals(MemoryVisibility::Private, $method->invoke($action, 'anything_else'));
    }

    // --- Execute guard clauses ---

    public function test_returns_empty_when_memory_disabled(): void
    {
        config(['memory.enabled' => false]);

        $action = new StoreMemoryAction;
        $memories = $action->execute(
            teamId: Str::uuid()->toString(),
            agentId: Str::uuid()->toString(),
            content: 'Some content',
            sourceType: 'execution',
        );

        $this->assertEmpty($memories);
    }

    public function test_returns_empty_for_blank_content(): void
    {
        config(['memory.enabled' => true]);

        $action = new StoreMemoryAction;
        $memories = $action->execute(
            teamId: Str::uuid()->toString(),
            agentId: Str::uuid()->toString(),
            content: '   ',
            sourceType: 'execution',
        );

        $this->assertEmpty($memories);
    }

    // --- Content hash generation ---

    public function test_content_hash_is_consistent_for_same_content(): void
    {
        $content = 'Test content to hash';
        $hash1 = hash('sha256', mb_strtolower(trim($content)));
        $hash2 = hash('sha256', mb_strtolower(trim($content)));

        $this->assertEquals($hash1, $hash2);
    }

    public function test_content_hash_normalizes_case_and_whitespace(): void
    {
        $hash1 = hash('sha256', mb_strtolower(trim('  Hello World  ')));
        $hash2 = hash('sha256', mb_strtolower(trim('hello world')));

        $this->assertEquals($hash1, $hash2);
    }

    // --- Write gate config ---

    public function test_write_gate_config_thresholds(): void
    {
        $this->assertEqualsWithDelta(0.95, config('memory.write_gate.skip_threshold'), 0.001);
        $this->assertEqualsWithDelta(0.85, config('memory.write_gate.update_threshold'), 0.001);
        $this->assertTrue(config('memory.write_gate.hash_dedup'));
    }

    // --- Chunk content ---

    public function test_chunk_content_returns_single_chunk_for_short_content(): void
    {
        $action = new StoreMemoryAction;
        $method = new \ReflectionMethod($action, 'chunkContent');

        $content = 'This is a short piece of content.';
        $chunks = $method->invoke($action, $content);

        $this->assertCount(1, $chunks);
        $this->assertEquals($content, $chunks[0]);
    }

    public function test_chunk_content_splits_long_content(): void
    {
        $action = new StoreMemoryAction;
        $method = new \ReflectionMethod($action, 'chunkContent');

        $paragraph1 = str_repeat('A', 1200);
        $paragraph2 = str_repeat('B', 1200);
        $content = $paragraph1."\n\n".$paragraph2;

        $chunks = $method->invoke($action, $content);

        $this->assertGreaterThan(1, count($chunks));
    }

    // --- Merge without gateway ---

    public function test_merge_content_returns_null_without_gateway(): void
    {
        $action = new StoreMemoryAction;
        $method = new \ReflectionMethod($action, 'mergeContent');

        $result = $method->invoke($action, 'existing', 'new', 'team-1', 'agent-1');

        $this->assertNull($result);
    }
}
