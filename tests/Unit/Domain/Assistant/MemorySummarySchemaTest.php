<?php

namespace Tests\Unit\Domain\Assistant;

use App\Domain\Assistant\DTOs\MemorySummarySchema;
use PHPUnit\Framework\TestCase;

class MemorySummarySchemaTest extends TestCase
{
    public function test_from_array_creates_schema_with_all_fields(): void
    {
        $data = [
            'task_overview' => 'User is building a REST API for orders',
            'current_state' => 'Three endpoints created, validation pending',
            'key_discoveries' => ['OrderController uses UUID PKs', 'Stripe webhook is async'],
            'next_steps' => ['Add input validation', 'Write tests'],
            'context_to_preserve' => 'Order model at app/Models/Order.php, team_id = abc-123',
        ];

        $schema = MemorySummarySchema::fromArray($data);

        $this->assertEquals('User is building a REST API for orders', $schema->taskOverview);
        $this->assertEquals('Three endpoints created, validation pending', $schema->currentState);
        $this->assertCount(2, $schema->keyDiscoveries);
        $this->assertCount(2, $schema->nextSteps);
        $this->assertStringContainsString('Order model', $schema->contextToPreserve);
    }

    public function test_from_array_truncates_long_fields(): void
    {
        $data = [
            'task_overview' => str_repeat('x', 1000),
            'current_state' => str_repeat('y', 500),
            'key_discoveries' => array_fill(0, 15, 'discovery'),
            'next_steps' => array_fill(0, 10, 'step'),
            'context_to_preserve' => str_repeat('z', 800),
        ];

        $schema = MemorySummarySchema::fromArray($data);

        $this->assertEquals(500, mb_strlen($schema->taskOverview));
        $this->assertEquals(300, mb_strlen($schema->currentState));
        $this->assertCount(10, $schema->keyDiscoveries);
        $this->assertCount(5, $schema->nextSteps);
        $this->assertEquals(500, mb_strlen($schema->contextToPreserve));
    }

    public function test_from_array_handles_missing_fields(): void
    {
        $schema = MemorySummarySchema::fromArray([]);

        $this->assertEquals('', $schema->taskOverview);
        $this->assertEquals('', $schema->currentState);
        $this->assertEmpty($schema->keyDiscoveries);
        $this->assertEmpty($schema->nextSteps);
        $this->assertEquals('', $schema->contextToPreserve);
    }

    public function test_to_array_roundtrips(): void
    {
        $data = [
            'task_overview' => 'Building feature X',
            'current_state' => 'In progress',
            'key_discoveries' => ['Found Y'],
            'next_steps' => ['Do Z'],
            'context_to_preserve' => 'Entity abc',
        ];

        $schema = MemorySummarySchema::fromArray($data);
        $result = $schema->toArray();

        $this->assertEquals($data, $result);
    }

    public function test_to_context_string_produces_xml(): void
    {
        $schema = new MemorySummarySchema(
            taskOverview: 'Test task',
            currentState: 'Running',
            keyDiscoveries: ['Found bug'],
            nextSteps: ['Fix it'],
            contextToPreserve: 'ID: 123',
        );

        $xml = $schema->toContextString();

        $this->assertStringContainsString('<memory_summary>', $xml);
        $this->assertStringContainsString('<task_overview>Test task</task_overview>', $xml);
        $this->assertStringContainsString('<current_state>Running</current_state>', $xml);
        $this->assertStringContainsString('Found bug', $xml);
        $this->assertStringContainsString('Fix it', $xml);
        $this->assertStringContainsString('ID: 123', $xml);
    }

    public function test_estimate_tokens_returns_reasonable_value(): void
    {
        $schema = new MemorySummarySchema(
            taskOverview: str_repeat('word ', 100),
            currentState: str_repeat('state ', 50),
            keyDiscoveries: ['discovery one', 'discovery two'],
            nextSteps: ['step one'],
            contextToPreserve: 'some context',
        );

        $tokens = $schema->estimateTokens();

        $this->assertGreaterThan(50, $tokens);
        $this->assertLessThan(500, $tokens);
    }

    public function test_json_schema_has_required_fields(): void
    {
        $schema = MemorySummarySchema::jsonSchema();

        $this->assertEquals('object', $schema['type']);
        $this->assertContains('task_overview', $schema['required']);
        $this->assertContains('current_state', $schema['required']);
        $this->assertContains('key_discoveries', $schema['required']);
        $this->assertContains('next_steps', $schema['required']);
        $this->assertContains('context_to_preserve', $schema['required']);
        $this->assertArrayHasKey('properties', $schema);
    }
}
