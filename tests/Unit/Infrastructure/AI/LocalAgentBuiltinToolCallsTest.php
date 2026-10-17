<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\LocalAgentOutputParser;
use PHPUnit\Framework\TestCase;

class LocalAgentBuiltinToolCallsTest extends TestCase
{
    private function assistant(array $blocks): string
    {
        return json_encode(['type' => 'assistant', 'message' => ['content' => $blocks]]);
    }

    private function tool(string $name): array
    {
        return ['type' => 'tool_use', 'id' => 'x', 'name' => $name, 'input' => ['secret' => 'hunter2']];
    }

    public function test_mixed_mcp_and_builtin_in_order_with_duplicates(): void
    {
        $out = implode("\n", [
            $this->assistant([['type' => 'text', 'text' => 'hi'], $this->tool('Bash'), $this->tool('mcp__fleetq__agent_list')]),
            json_encode(['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'x']]]]),
            $this->assistant([$this->tool('Read')]),
            $this->assistant([$this->tool('Bash')]),
            json_encode(['type' => 'result', 'result' => 'done']),
        ]);

        $r = LocalAgentOutputParser::builtinToolCalls($out);

        $this->assertSame(['Bash', 'Read', 'Bash'], $r['names']);
        $this->assertFalse($r['truncated']);
    }

    public function test_only_mcp_calls_yield_empty_list(): void
    {
        $r = LocalAgentOutputParser::builtinToolCalls($this->assistant([$this->tool('mcp__a__b')]));

        $this->assertSame([], $r['names']);
    }

    public function test_malformed_lines_and_odd_shapes_are_skipped(): void
    {
        $out = implode("\n", [
            '{not json',
            '',
            '"just a string"',
            json_encode(['type' => 'assistant', 'message' => ['content' => 'plain']]),
            json_encode(['type' => 'assistant']),
            $this->assistant(['bad', ['type' => 'tool_use'], ['type' => 'tool_use', 'name' => 7], $this->tool('Glob')]),
        ]);

        $this->assertSame(['Glob'], LocalAgentOutputParser::builtinToolCalls($out)['names']);
    }

    public function test_empty_input(): void
    {
        $this->assertSame(['names' => [], 'truncated' => false], LocalAgentOutputParser::builtinToolCalls(''));
    }

    public function test_cap_at_500_sets_truncated(): void
    {
        $blocks = array_fill(0, 501, $this->tool('Bash'));
        $r = LocalAgentOutputParser::builtinToolCalls($this->assistant($blocks));

        $this->assertCount(500, $r['names']);
        $this->assertTrue($r['truncated']);
    }

    public function test_exactly_500_is_not_truncated(): void
    {
        $r = LocalAgentOutputParser::builtinToolCalls($this->assistant(array_fill(0, 500, $this->tool('Bash'))));

        $this->assertCount(500, $r['names']);
        $this->assertFalse($r['truncated']);
    }
}
