<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\LocalAgentPromptBuilder;
use PHPUnit\Framework\TestCase;

class LocalAgentPromptBuilderResumeTest extends TestCase
{
    public function test_claude_code_keeps_no_session_persistence_by_default(): void
    {
        $command = LocalAgentPromptBuilder::buildCommand('claude-code', '/usr/bin/claude', 'sonnet');

        $this->assertStringContainsString(' --no-session-persistence ', $command);
        $this->assertStringNotContainsString('--resume', $command);
        $this->assertSame(
            "unset CLAUDECODE; /usr/bin/claude --print --output-format json --dangerously-skip-permissions --no-session-persistence --strict-mcp-config --mcp-config '{\"mcpServers\":{}}' --model 'sonnet'",
            $command,
        );
    }

    public function test_persist_session_drops_no_session_persistence(): void
    {
        $command = LocalAgentPromptBuilder::buildCommand('claude-code', '/usr/bin/claude', 'sonnet', 'agent.workflow_step', null, null, true);

        $this->assertStringNotContainsString('--no-session-persistence', $command);
        $this->assertStringNotContainsString('--resume', $command);
        $this->assertStringContainsString('--strict-mcp-config', $command);
    }

    public function test_resume_session_replaces_no_session_persistence(): void
    {
        $command = LocalAgentPromptBuilder::buildCommand('claude-code', '/usr/bin/claude', 'sonnet', 'agent.workflow_step', null, null, false, 'abc-123');

        $this->assertStringContainsString(" --resume 'abc-123'", $command);
        $this->assertStringNotContainsString('--no-session-persistence', $command);
    }

    public function test_resume_session_id_is_shell_escaped(): void
    {
        $command = LocalAgentPromptBuilder::buildCommand('claude-code', '/usr/bin/claude', null, null, null, null, true, "x'; rm -rf /");

        $this->assertStringContainsString(" --resume 'x'\\''; rm -rf /'", $command);
    }

    public function test_other_agents_ignore_session_flags(): void
    {
        $this->assertSame(
            LocalAgentPromptBuilder::buildCommand('codex', '/usr/bin/codex', 'gpt'),
            LocalAgentPromptBuilder::buildCommand('codex', '/usr/bin/codex', 'gpt', null, null, null, true, 'abc-123'),
        );
    }
}
