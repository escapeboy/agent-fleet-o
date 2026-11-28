<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\LocalAgentOutputParser;
use PHPUnit\Framework\TestCase;

class LocalAgentPermissionDenialsTest extends TestCase
{
    /**
     * @return list<array<string, mixed>>
     */
    private function denialsFixture(): array
    {
        return [
            ['tool_name' => 'Bash', 'tool_use_id' => 'toolu_1', 'tool_input' => ['command' => 'git push origin main']],
            ['tool_name' => 'WebFetch', 'tool_use_id' => 'toolu_2', 'tool_input' => ['url' => 'https://example.test']],
        ];
    }

    public function test_reads_denials_from_json_result_object(): void
    {
        $raw = json_encode([
            'type' => 'result',
            'result' => 'done',
            'session_id' => 'abc',
            'permission_denials' => $this->denialsFixture(),
        ]);

        $parsed = LocalAgentOutputParser::parseOutput('claude-code', (string) $raw);

        $this->assertSame([
            ['tool_name' => 'Bash', 'tool_input' => ['command' => 'git push origin main']],
            ['tool_name' => 'WebFetch', 'tool_input' => ['url' => 'https://example.test']],
        ], LocalAgentOutputParser::permissionDenials($parsed['structured']));
    }

    public function test_reads_denials_from_stream_json_result_event(): void
    {
        $lines = [
            json_encode(['type' => 'system', 'subtype' => 'init']),
            json_encode(['type' => 'assistant', 'message' => ['content' => [['type' => 'text', 'text' => 'trying']]]]),
            json_encode(['type' => 'result', 'result' => 'blocked', 'permission_denials' => $this->denialsFixture()]),
        ];

        $parsed = LocalAgentOutputParser::parseOutput('claude-code', implode("\n", $lines));
        $denials = LocalAgentOutputParser::permissionDenials($parsed['structured']);

        $this->assertCount(2, $denials);
        $this->assertSame('Bash', $denials[0]['tool_name']);
        $this->assertSame('WebFetch', $denials[1]['tool_name']);
    }

    public function test_returns_empty_without_denials(): void
    {
        $parsed = LocalAgentOutputParser::parseOutput('claude-code', (string) json_encode(['type' => 'result', 'result' => 'ok']));

        $this->assertSame([], LocalAgentOutputParser::permissionDenials($parsed['structured']));
        $this->assertSame([], LocalAgentOutputParser::permissionDenials(null));
        $this->assertSame([], LocalAgentOutputParser::permissionDenials(['permission_denials' => 'nope']));
    }

    public function test_skips_malformed_entries_and_defaults_input(): void
    {
        $denials = LocalAgentOutputParser::permissionDenials(['permission_denials' => [
            ['tool_input' => ['command' => 'ls']],
            ['tool_name' => 42],
            'garbage',
            ['tool_name' => 'WebSearch', 'tool_input' => 'not-an-array'],
            ['tool_name' => 'Glob'],
        ]]);

        $this->assertSame([
            ['tool_name' => 'WebSearch', 'tool_input' => []],
            ['tool_name' => 'Glob', 'tool_input' => []],
        ], $denials);
    }

    public function test_caps_the_number_of_denials(): void
    {
        $many = array_fill(0, 50, ['tool_name' => 'WebFetch', 'tool_input' => []]);

        $this->assertCount(
            LocalAgentOutputParser::MAX_PERMISSION_DENIALS,
            LocalAgentOutputParser::permissionDenials(['permission_denials' => $many]),
        );
    }
}
