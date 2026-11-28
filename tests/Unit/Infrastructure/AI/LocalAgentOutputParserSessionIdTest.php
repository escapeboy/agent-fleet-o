<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\LocalAgentOutputParser;
use PHPUnit\Framework\TestCase;

class LocalAgentOutputParserSessionIdTest extends TestCase
{
    public function test_reads_session_id_from_claude_code_json_result(): void
    {
        $parsed = LocalAgentOutputParser::parseOutput(
            'claude-code',
            '{"type":"result","result":"hello","session_id":"5f1c2b9e-1234-4abc-9def-0123456789ab"}',
        );

        $this->assertSame('5f1c2b9e-1234-4abc-9def-0123456789ab', LocalAgentOutputParser::sessionId($parsed['structured']));
    }

    public function test_reads_session_id_from_stream_json_result_event(): void
    {
        $stream = implode("\n", [
            '{"type":"system","subtype":"init","session_id":"sess-1"}',
            '{"type":"assistant","message":{"content":[{"type":"text","text":"hi"}]}}',
            '{"type":"result","result":"hi","session_id":"sess-1"}',
        ]);

        $parsed = LocalAgentOutputParser::parseOutput('claude-code', $stream);

        $this->assertSame('sess-1', LocalAgentOutputParser::sessionId($parsed['structured']));
    }

    public function test_returns_null_for_missing_or_invalid_values(): void
    {
        $this->assertNull(LocalAgentOutputParser::sessionId(null));
        $this->assertNull(LocalAgentOutputParser::sessionId(['result' => 'x']));
        $this->assertNull(LocalAgentOutputParser::sessionId(['session_id' => 123]));
        $this->assertNull(LocalAgentOutputParser::sessionId(['session_id' => '']));
        $this->assertNull(LocalAgentOutputParser::sessionId(['session_id' => 'has space']));
        $this->assertNull(LocalAgentOutputParser::sessionId(['session_id' => "x'; rm -rf /"]));
        $this->assertNull(LocalAgentOutputParser::sessionId(['session_id' => str_repeat('a', 101)]));
    }

    public function test_returns_null_for_codex_output(): void
    {
        $stream = implode("\n", [
            '{"type":"item.completed","item":{"type":"agent_message","text":"done"}}',
            '{"type":"turn.completed","usage":{"input_tokens":1,"output_tokens":1}}',
        ]);

        $parsed = LocalAgentOutputParser::parseOutput('codex', $stream);

        $this->assertNull(LocalAgentOutputParser::sessionId($parsed['structured']));
    }
}
