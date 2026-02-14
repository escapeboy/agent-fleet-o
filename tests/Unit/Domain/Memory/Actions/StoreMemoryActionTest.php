<?php

namespace Tests\Unit\Domain\Memory\Actions;

use App\Domain\Memory\Actions\StoreMemoryAction;
use Tests\TestCase;

class StoreMemoryActionTest extends TestCase
{
    public function test_chunk_content_returns_single_chunk_for_short_content(): void
    {
        $action = new StoreMemoryAction;
        $method = new \ReflectionMethod($action, 'chunkContent');

        $content = 'This is a short piece of content.';
        $chunks = $method->invoke($action, $content);

        $this->assertCount(1, $chunks);
        $this->assertEquals($content, $chunks[0]);
    }

    public function test_chunk_content_splits_long_content_by_paragraphs(): void
    {
        $action = new StoreMemoryAction;
        $method = new \ReflectionMethod($action, 'chunkContent');

        // Create content longer than default max_chunk_size (2000 chars)
        $paragraph1 = str_repeat('A', 1200);
        $paragraph2 = str_repeat('B', 1200);
        $content = $paragraph1."\n\n".$paragraph2;

        $chunks = $method->invoke($action, $content);

        $this->assertGreaterThan(1, count($chunks));
        $this->assertStringContainsString('A', $chunks[0]);
    }

    public function test_execute_returns_empty_array_when_content_is_empty(): void
    {
        $action = new StoreMemoryAction;

        $result = $action->execute(
            teamId: 'team-1',
            agentId: 'agent-1',
            content: '',
            sourceType: 'execution',
        );

        $this->assertEmpty($result);
    }

    public function test_execute_returns_empty_array_when_content_is_whitespace(): void
    {
        $action = new StoreMemoryAction;

        $result = $action->execute(
            teamId: 'team-1',
            agentId: 'agent-1',
            content: '   ',
            sourceType: 'execution',
        );

        $this->assertEmpty($result);
    }

    public function test_execute_returns_empty_array_when_memory_disabled(): void
    {
        config(['memory.enabled' => false]);

        $action = new StoreMemoryAction;

        $result = $action->execute(
            teamId: 'team-1',
            agentId: 'agent-1',
            content: 'Some meaningful content',
            sourceType: 'execution',
        );

        $this->assertEmpty($result);

        config(['memory.enabled' => true]);
    }
}
