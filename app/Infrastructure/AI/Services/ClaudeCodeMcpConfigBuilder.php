<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Translates a collection of FleetQ Tool models into Claude Code's
 * `mcpServers` config block. The output is meant to be written to
 * `<HOME>/.claude.json` for an ephemeral claude-code-vps run, so the
 * CLI can reach the same MCP servers (HTTP + stdio) the agent has
 * attached on the platform side.
 *
 * Built-in tools (Bash/Filesystem/Browser) are skipped — Claude Code
 * has its own implementations of those and bridging them via MCP would
 * conflict.
 */
class ClaudeCodeMcpConfigBuilder
{
    /**
     * @param  Collection<int, Tool>  $tools
     * @return array{mcpServers?: array<string, array<string, mixed>>}
     */
    public function build(Collection $tools): array
    {
        $servers = [];

        foreach ($tools as $tool) {
            $entry = match ($tool->type) {
                ToolType::McpHttp => $this->buildHttpEntry($tool),
                ToolType::McpStdio => $this->buildStdioEntry($tool),
                default => null,
            };

            if ($entry === null) {
                continue;
            }

            $servers[$this->serverName($tool)] = $entry;
        }

        return $servers === [] ? [] : ['mcpServers' => $servers];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildHttpEntry(Tool $tool): ?array
    {
        $config = $tool->transport_config ?? [];
        $url = $config['url'] ?? null;

        if (! is_string($url) || $url === '') {
            Log::info('ClaudeCodeMcpConfigBuilder: skipping mcp_http tool with no url', [
                'tool_id' => $tool->id,
                'tool_name' => $tool->name,
            ]);

            return null;
        }

        $headers = (array) ($config['headers'] ?? []);
        $headers = $this->mergeCredentialAuth($tool, $headers);

        $entry = [
            'type' => 'http',
            'url' => $url,
        ];

        if ($headers !== []) {
            $entry['headers'] = $headers;
        }

        return $entry;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildStdioEntry(Tool $tool): ?array
    {
        $config = $tool->transport_config ?? [];
        $command = $config['command'] ?? null;

        if (! is_string($command) || $command === '') {
            Log::info('ClaudeCodeMcpConfigBuilder: skipping mcp_stdio tool with no command', [
                'tool_id' => $tool->id,
                'tool_name' => $tool->name,
            ]);

            return null;
        }

        $entry = ['command' => $command];

        $args = $config['args'] ?? [];
        if (is_array($args) && $args !== []) {
            $entry['args'] = array_values($args);
        }

        $env = (array) ($config['env'] ?? []);
        $env = $this->mergeCredentialEnv($tool, $env);

        // Strip empty env values — they'd shadow whatever the CLI inherits
        $env = array_filter($env, fn ($v) => is_string($v) && $v !== '');

        if ($env !== []) {
            $entry['env'] = $env;
        }

        return $entry;
    }

    /**
     * Mirrors ToolTranslator::translateMcpTool credential merging:
     * inline `credentials.api_key` / `credentials.bearer_token` becomes
     * an `Authorization: Bearer <token>` header, but only when no
     * Authorization header is already pre-baked into transport_config.
     *
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function mergeCredentialAuth(Tool $tool, array $headers): array
    {
        if (isset($headers['Authorization'])) {
            return $headers;
        }

        $credentials = (array) $tool->credentials;
        $token = $credentials['api_key'] ?? $credentials['bearer_token'] ?? null;

        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $headers;
    }

    /**
     * For stdio tools, env values from transport_config can carry
     * placeholder names that match credential keys. Resolve any
     * empty-string env keys with their matching credential entry
     * (case-insensitive). Already-populated env values are preserved.
     *
     * @param  array<string, string>  $env
     * @return array<string, string>
     */
    private function mergeCredentialEnv(Tool $tool, array $env): array
    {
        $credentials = (array) $tool->credentials;
        if ($credentials === []) {
            return $env;
        }

        $lookup = [];
        foreach ($credentials as $key => $value) {
            if (is_string($value)) {
                $lookup[strtoupper($key)] = $value;
            }
        }

        foreach ($env as $key => $value) {
            if (is_string($value) && $value !== '') {
                continue;
            }

            $upper = strtoupper((string) $key);
            if (isset($lookup[$upper])) {
                $env[$key] = $lookup[$upper];
            }
        }

        return $env;
    }

    private function serverName(Tool $tool): string
    {
        $slug = $tool->slug ?? '';
        if (is_string($slug) && $slug !== '') {
            return preg_replace('/[^a-z0-9_]/i', '_', $slug) ?? $tool->id;
        }

        return $tool->id;
    }
}
