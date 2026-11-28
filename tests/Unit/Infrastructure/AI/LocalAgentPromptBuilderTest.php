<?php

namespace Tests\Unit\Infrastructure\AI;

use App\Infrastructure\AI\Gateways\LocalAgentPromptBuilder;
use Tests\TestCase;

class LocalAgentPromptBuilderTest extends TestCase
{
    private const MCP = ['mcpServers' => ['tool_alpha' => ['type' => 'http', 'url' => 'https://a.example.test/mcp']]];

    private const SETTINGS = ['permissions' => ['allow' => ['Read', 'Bash'], 'deny' => ['Bash(sudo:*)']]];

    /**
     * @param  array<int, string>  $args
     * @return array<string, mixed>
     */
    private function settingsFromArgs(array $args): array
    {
        $this->assertNotContains('--dangerously-skip-permissions', $args);

        $modeAt = array_search('--permission-mode', $args, true);
        $this->assertIsInt($modeAt);
        $this->assertSame('dontAsk', $args[$modeAt + 1]);

        $settingsAt = array_search('--settings', $args, true);
        $this->assertIsInt($settingsAt);
        $decoded = json_decode($args[$settingsAt + 1], true);
        $this->assertIsArray($decoded);

        // --tools "" stays.
        $toolsAt = array_search('--tools', $args, true);
        $this->assertIsInt($toolsAt);
        $this->assertSame('', $args[$toolsAt + 1]);

        return $decoded;
    }

    public function test_build_command_uses_dont_ask_with_default_settings(): void
    {
        $command = LocalAgentPromptBuilder::buildCommand('claude-code', '/usr/bin/claude', null, null, null, self::MCP);

        $this->assertStringNotContainsString('--dangerously-skip-permissions', $command);
        $this->assertStringContainsString('--permission-mode dontAsk --settings ', $command);
        $this->assertStringStartsWith('unset CLAUDECODE; ', $command);

        $expected = escapeshellarg('{"permissions":{"allow":["Read","Glob","Grep","mcp__tool_alpha"],"deny":["Bash(sudo:*)"]}}');
        $this->assertStringContainsString('--settings '.$expected, $command);
        $this->assertStringContainsString('--strict-mcp-config --mcp-config', $command);
    }

    public function test_build_command_uses_given_settings(): void
    {
        $command = LocalAgentPromptBuilder::buildCommand('claude-code', '/usr/bin/claude', 'sonnet', null, null, null, self::SETTINGS);

        $this->assertStringNotContainsString('--dangerously-skip-permissions', $command);
        $this->assertStringContainsString(
            '--permission-mode dontAsk --settings '.escapeshellarg('{"permissions":{"allow":["Read","Bash"],"deny":["Bash(sudo:*)"]}}'),
            $command,
        );
    }

    public function test_assistant_args_use_dont_ask(): void
    {
        $default = $this->settingsFromArgs(
            LocalAgentPromptBuilder::buildClaudeCodeAssistantArgs('/usr/bin/claude', 'sys', null, null, self::MCP),
        );
        $this->assertContains('mcp__tool_alpha', $default['permissions']['allow']);
        $this->assertNotContains('Bash', $default['permissions']['allow']);

        $given = $this->settingsFromArgs(
            LocalAgentPromptBuilder::buildClaudeCodeAssistantArgs('/usr/bin/claude', 'sys', null, null, null, self::SETTINGS),
        );
        $this->assertSame(self::SETTINGS, $given);
    }

    public function test_assistant_stream_args_use_dont_ask(): void
    {
        $default = $this->settingsFromArgs(
            LocalAgentPromptBuilder::buildClaudeCodeAssistantStreamArgs('/usr/bin/claude', 'sys', null, null, self::MCP),
        );
        $this->assertContains('mcp__tool_alpha', $default['permissions']['allow']);

        $given = $this->settingsFromArgs(
            LocalAgentPromptBuilder::buildClaudeCodeAssistantStreamArgs('/usr/bin/claude', 'sys', null, null, null, self::SETTINGS),
        );
        $this->assertSame(self::SETTINGS, $given);
    }
}
