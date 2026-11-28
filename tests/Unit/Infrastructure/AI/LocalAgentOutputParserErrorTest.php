<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\LocalAgentOutputParser;
use PHPUnit\Framework\TestCase;

class LocalAgentOutputParserErrorTest extends TestCase
{
    private function fixture(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3).'/Fixtures/claude-code/'.$name);
    }

    public function test_detect_error_returns_null_for_successful_result(): void
    {
        $this->assertNull(LocalAgentOutputParser::detectError($this->fixture('success.json')));
    }

    public function test_detect_error_ignores_allowed_warning_rate_limit_event(): void
    {
        $this->assertNull(LocalAgentOutputParser::detectError($this->fixture('rate-limit-warning-success.jsonl')));
    }

    public function test_detect_error_returns_null_without_result_event(): void
    {
        $this->assertNull(LocalAgentOutputParser::detectError(''));
        $this->assertNull(LocalAgentOutputParser::detectError('plain text output'));
        $this->assertNull(LocalAgentOutputParser::detectError('{"type":"item.completed","item":{"type":"agent_message","text":"done"}}'));
    }

    public function test_detect_error_reports_is_error_result(): void
    {
        $error = LocalAgentOutputParser::detectError($this->fixture('is-error.json'));

        $this->assertNotNull($error);
        $this->assertSame('success', $error['subtype']);
        $this->assertStringStartsWith('API Error: 500', $error['message']);
        $this->assertFalse($error['rate_limited']);
        $this->assertNull($error['resets_at']);
        $this->assertNull($error['rate_limit_type']);
    }

    public function test_detect_error_reports_error_max_turns_without_result_text(): void
    {
        $error = LocalAgentOutputParser::detectError($this->fixture('error-max-turns.jsonl'));

        $this->assertNotNull($error);
        $this->assertSame('error_max_turns', $error['subtype']);
        $this->assertSame('error_max_turns', $error['message']);
        $this->assertFalse($error['rate_limited']);
    }

    public function test_detect_error_reports_error_subtype_even_without_is_error_flag(): void
    {
        $error = LocalAgentOutputParser::detectError('{"type":"result","subtype":"error_during_execution"}');

        $this->assertNotNull($error);
        $this->assertSame('error_during_execution', $error['subtype']);
    }

    public function test_detect_error_reports_rejected_rate_limit(): void
    {
        $error = LocalAgentOutputParser::detectError($this->fixture('rate-limit-rejected.jsonl'));

        $this->assertNotNull($error);
        $this->assertTrue($error['rate_limited']);
        $this->assertSame(1767225600, $error['resets_at']);
        $this->assertSame('five_hour', $error['rate_limit_type']);
        $this->assertSame('Claude AI usage limit reached|1767225600', $error['message']);
    }

    public function test_detect_error_reads_reset_time_from_result_text(): void
    {
        $error = LocalAgentOutputParser::detectError(
            '{"type":"result","subtype":"success","is_error":true,"result":"Claude AI usage limit reached|1767225600"}',
        );

        $this->assertNotNull($error);
        $this->assertTrue($error['rate_limited']);
        $this->assertSame(1767225600, $error['resets_at']);
        $this->assertNull($error['rate_limit_type']);
    }

    public function test_parse_output_unchanged_for_success_json(): void
    {
        $parsed = LocalAgentOutputParser::parseOutput('claude-code', $this->fixture('success.json'));

        $this->assertSame('ok', $parsed['content']);
        $this->assertSame([
            'type' => 'result',
            'subtype' => 'success',
            'is_error' => false,
            'duration_ms' => 1200,
            'num_turns' => 1,
            'result' => 'ok',
            'session_id' => 'sess-success',
        ], $parsed['structured']);
    }

    public function test_parse_output_unchanged_for_codex_jsonl(): void
    {
        $stream = implode("\n", [
            '{"type":"item.completed","item":{"type":"agent_message","text":"first"}}',
            '{"type":"item.completed","item":{"type":"agent_message","text":"second"}}',
            '{"type":"turn.completed","usage":{"input_tokens":5,"output_tokens":7}}',
        ]);

        $parsed = LocalAgentOutputParser::parseOutput('codex', $stream);

        $this->assertSame("first\n\nsecond", $parsed['content']);
        $this->assertSame([
            'type' => 'result',
            'result' => "first\n\nsecond",
            'usage' => ['input_tokens' => 5, 'output_tokens' => 7],
        ], $parsed['structured']);
    }
}
