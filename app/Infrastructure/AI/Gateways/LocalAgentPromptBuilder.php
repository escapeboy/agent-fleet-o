<?php

namespace App\Infrastructure\AI\Gateways;

use App\Infrastructure\AI\DTOs\AiRequestDTO;
use RuntimeException;

final class LocalAgentPromptBuilder
{
    public static function buildPrompt(AiRequestDTO $request): string
    {
        $parts = [];

        if ($request->systemPrompt) {
            $parts[] = $request->systemPrompt;
        }

        $parts[] = $request->userPrompt;

        return implode("\n\n", $parts);
    }

    /**
     * Check if the agent reads its prompt from stdin (true) or needs it as a CLI argument (false).
     */
    public static function readsFromStdin(string $agentKey): bool
    {
        return in_array($agentKey, ['codex', 'claude-code', 'gemini-cli'], true);
    }

    public static function buildCommand(string $agentKey, string $binaryPath, ?string $model = null, ?string $purpose = null, ?string $prompt = null): string
    {
        $modelFlag = $model ? ' --model '.escapeshellarg($model) : '';

        // Unset CLAUDECODE to prevent "nested session" detection when spawning Claude Code
        $preamble = 'unset CLAUDECODE; ';

        $isAssistant = $purpose === 'platform_assistant';

        // Codex assistant: enable --full-auto for MCP tool execution and
        // connect to our FleetQ MCP server (replaces advisory mode).
        // Non-assistant Codex: --full-auto, no MCP override.
        if ($isAssistant) {
            // Uses TOML dotted-key syntax (not JSON) — codex parses -c values as TOML.
            $codexMcpFlag = ' -c '.escapeshellarg('mcp_servers.agent-fleet.command="php"')
                .' -c '.escapeshellarg('mcp_servers.agent-fleet.args=["artisan","mcp:start","agent-fleet"]');
        } else {
            $codexMcpFlag = '';
        }

        $escapedPrompt = $prompt ? ' '.escapeshellarg($prompt) : '';

        return match ($agentKey) {
            'codex' => $preamble."{$binaryPath} exec --json --full-auto{$codexMcpFlag}{$modelFlag}",
            'claude-code' => $preamble."{$binaryPath} --print --output-format json --dangerously-skip-permissions --no-session-persistence --strict-mcp-config --mcp-config ".escapeshellarg('{"mcpServers":{}}').$modelFlag,
            'gemini-cli' => "{$binaryPath} -p --output-format json".($model ? ' -m '.escapeshellarg($model) : ''),
            'kiro' => "{$binaryPath} chat --no-interactive{$escapedPrompt}",
            'aider' => "{$binaryPath} --yes --no-git --no-auto-commits".($model ? ' --model '.escapeshellarg($model) : '').' --message'.($prompt ? ' '.escapeshellarg($prompt) : ''),
            'amp' => "{$binaryPath} -x --stream-json{$escapedPrompt}",
            'opencode' => "{$binaryPath} run --format json".($model ? ' -m '.escapeshellarg($model) : '').$escapedPrompt,
            // -p is a boolean flag (print/headless mode); prompt is a positional argument appended at the end.
            // --force skips file-write confirmations (equivalent to --dangerously-skip-permissions in Claude Code).
            // --trust grants workspace access without interactive confirmation.
            // --approve-mcps prevents hanging on MCP approval prompts.
            // Skip --model when 'auto' (Cursor's default behavior routes to best available model).
            'cursor' => "{$binaryPath} -p --output-format stream-json --force --trust --approve-mcps"
                .($model && $model !== 'auto' ? ' --model '.escapeshellarg($model) : '')
                .$escapedPrompt,
            default => throw new RuntimeException("No command template for agent: {$agentKey}"),
        };
    }

    /**
     * Build Claude Code process arguments for assistant mode.
     *
     * When $systemPromptFile is provided, uses --system-prompt-file to avoid
     * the ARG_MAX limit when the system prompt is very large (e.g. 200+ tools).
     * When null, falls back to inline --system-prompt.
     *
     * @return array<string>
     */
    public static function buildClaudeCodeAssistantArgs(string $binaryPath, string $systemPrompt, ?string $model = null, ?string $systemPromptFile = null): array
    {
        $systemPromptArgs = $systemPromptFile
            ? ['--system-prompt-file', $systemPromptFile]
            : ['--system-prompt', $systemPrompt];

        $args = array_merge(
            [$binaryPath, '--print', '--output-format', 'json'],
            $systemPromptArgs,
            ['--tools', '', '--dangerously-skip-permissions', '--no-session-persistence', '--strict-mcp-config', '--mcp-config', '{"mcpServers":{}}'],
        );

        if ($model) {
            $args[] = '--model';
            $args[] = $model;
        }

        return $args;
    }

    /**
     * Streaming variant — same system-prompt / --tools "" separation, but
     * with --output-format stream-json --verbose so the caller can consume
     * progressive JSONL events from stdout and emit incremental text chunks
     * to the UI instead of waiting for the final JSON payload.
     *
     * @return array<string>
     */
    public static function buildClaudeCodeAssistantStreamArgs(string $binaryPath, string $systemPrompt, ?string $model = null, ?string $systemPromptFile = null): array
    {
        $systemPromptArgs = $systemPromptFile
            ? ['--system-prompt-file', $systemPromptFile]
            : ['--system-prompt', $systemPrompt];

        $args = array_merge(
            [$binaryPath, '--print', '--output-format', 'stream-json', '--verbose'],
            $systemPromptArgs,
            ['--tools', '', '--dangerously-skip-permissions', '--no-session-persistence', '--strict-mcp-config', '--mcp-config', '{"mcpServers":{}}'],
        );

        if ($model) {
            $args[] = '--model';
            $args[] = $model;
        }

        return $args;
    }
}
