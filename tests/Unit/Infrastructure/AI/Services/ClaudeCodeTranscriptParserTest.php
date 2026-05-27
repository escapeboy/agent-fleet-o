<?php

namespace Tests\Unit\Infrastructure\AI\Services;

use App\Infrastructure\AI\Services\ClaudeCodeTranscriptParser;
use Tests\TestCase;

class ClaudeCodeTranscriptParserTest extends TestCase
{
    private function fixture(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/claude-code-transcript.jsonl'));
    }

    public function test_parses_turns_session_tokens_and_tools(): void
    {
        $parsed = (new ClaudeCodeTranscriptParser)->parse($this->fixture());

        // 4 usable turns: malformed line + summary line are skipped.
        $this->assertSame(4, $parsed->turnCount());
        $this->assertSame('sess-abc', $parsed->sessionId);

        // input_tokens + cache_read_input_tokens on turn 1; user turns have no usage.
        $this->assertSame(2000 + 1300, $parsed->totalPromptTokens());
        $this->assertSame(40 + 10, $parsed->totalCompletionTokens());
        $this->assertSame(1, $parsed->toolCallCount());

        $assistantTurn = $parsed->turns[1];
        $this->assertTrue($assistantTurn->isAssistant());
        $this->assertSame('claude-opus-4-7', $assistantTurn->model);
        $this->assertSame(2000, $assistantTurn->promptTokens);
        $this->assertSame('Running deploy.', $assistantTurn->text);
        $this->assertSame('Bash', $assistantTurn->toolCalls[0]['name']);
        $this->assertSame('git push origin master', $assistantTurn->toolCalls[0]['input']['command']);
        $this->assertGreaterThan(0, $assistantTurn->timestampNanos);
    }

    public function test_skips_malformed_lines_but_keeps_valid_ones(): void
    {
        $jsonl = implode("\n", [
            '{"type":"assistant","message":{"role":"assistant","model":"m","content":[{"type":"text","text":"ok"}],"usage":{"output_tokens":5}}}',
            '{ this is not valid json',
            '',
            'plain text noise',
            '{"type":"user","message":{"role":"user","content":"hi"}}',
        ]);

        $parsed = (new ClaudeCodeTranscriptParser)->parse($jsonl);

        $this->assertSame(2, $parsed->turnCount());
        $this->assertSame(5, $parsed->totalCompletionTokens());
    }

    public function test_handles_string_content_and_user_role(): void
    {
        $parsed = (new ClaudeCodeTranscriptParser)->parse(
            '{"type":"user","message":{"role":"user","content":"just text"}}',
        );

        $this->assertSame(1, $parsed->turnCount());
        $this->assertSame('user', $parsed->turns[0]->role);
        $this->assertSame('just text', $parsed->turns[0]->text);
        $this->assertSame([], $parsed->turns[0]->toolCalls);
    }

    public function test_empty_input_yields_no_turns(): void
    {
        $parsed = (new ClaudeCodeTranscriptParser)->parse("\n  \n");

        $this->assertSame(0, $parsed->turnCount());
        $this->assertNull($parsed->sessionId);
    }
}
