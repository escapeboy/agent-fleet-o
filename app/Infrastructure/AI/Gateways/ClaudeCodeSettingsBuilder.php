<?php

namespace App\Infrastructure\AI\Gateways;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Models\Tool;
use App\Infrastructure\AI\Services\ClaudeCodeMcpConfigBuilder;
use Illuminate\Support\Facades\Log;

/**
 * Builds the per-run Claude Code `--settings` payload used together with
 * `--permission-mode dontAsk`. In dontAsk mode every tool call that is not
 * explicitly allowed is denied, so this list is the agent's whole toolbox:
 *
 *  - Read/Glob/Grep are always allowed.
 *  - Edit/Write are allowed only inside the run's working directory.
 *  - Bash is allowed unless agent.config.allow_shell === false.
 *  - Bash(sudo:*) is always denied.
 *  - Every MCP server in the run's --mcp-config is allowed as mcp__<server>.
 *  - Tools in Agent.tool_deny_list are denied as mcp__<server>.
 */
final class ClaudeCodeSettingsBuilder
{
    /** @var list<string> */
    private const ALWAYS_ALLOWED = ['Read', 'Glob', 'Grep'];

    /** @var list<string> */
    private const ALWAYS_DENIED = ['Bash(sudo:*)'];

    public function __construct(private readonly ClaudeCodeMcpConfigBuilder $mcpConfigBuilder) {}

    /**
     * @param  array<string, mixed>|null  $mcpConfig  `['mcpServers' => [...]]` or the bare server map
     * @param  array<int, string>  $deniedServerNames
     * @return array{permissions: array{allow: list<string>, deny: list<string>}}
     */
    public static function build(?string $workingDirectory, ?array $mcpConfig, bool $allowShell = true, array $deniedServerNames = []): array
    {
        $allow = self::ALWAYS_ALLOWED;
        $deny = self::ALWAYS_DENIED;

        if ($allowShell) {
            $allow[] = 'Bash';
        }

        if ($workingDirectory !== null && $workingDirectory !== '') {
            $resolved = realpath($workingDirectory);
            $dir = rtrim($resolved !== false ? $resolved : $workingDirectory, '/');
            $pattern = '//'.ltrim($dir, '/').'/**';
            $allow[] = 'Edit('.$pattern.')';
            $allow[] = 'Write('.$pattern.')';
        }

        $deniedRules = [];
        foreach ($deniedServerNames as $name) {
            if ($name !== '') {
                $deniedRules[] = 'mcp__'.$name;
            }
        }

        $servers = [];
        if ($mcpConfig !== null) {
            $servers = isset($mcpConfig['mcpServers']) && is_array($mcpConfig['mcpServers'])
                ? $mcpConfig['mcpServers']
                : $mcpConfig;
        }

        foreach (array_keys($servers) as $serverName) {
            $rule = 'mcp__'.$serverName;
            if (! in_array($rule, $deniedRules, true)) {
                $allow[] = $rule;
            }
        }

        $deny = array_merge($deny, $deniedRules);

        return [
            'permissions' => [
                'allow' => array_values(array_unique($allow)),
                'deny' => array_values(array_unique($deny)),
            ],
        ];
    }

    /**
     * Settings for a specific agent run. Never throws: on any failure it
     * falls back to the defaults without agent-specific denies.
     *
     * @param  array<string, mixed>|null  $mcpConfig
     * @return array{permissions: array{allow: list<string>, deny: list<string>}}
     */
    public function forAgent(?Agent $agent, ?string $workingDirectory, ?array $mcpConfig): array
    {
        if ($agent === null) {
            return self::build($workingDirectory, $mcpConfig);
        }

        // Only a strict false turns the shell off; a missing key keeps it on.
        $config = is_array($agent->config) ? $agent->config : [];
        $allowShell = ($config['allow_shell'] ?? true) !== false;

        try {
            $deniedIds = [];
            foreach ((array) ($agent->tool_deny_list ?? []) as $id) {
                if ($id !== '') {
                    $deniedIds[] = $id;
                }
            }

            $deniedServerNames = [];
            if ($deniedIds !== []) {
                $tools = Tool::withoutGlobalScopes()
                    ->where('team_id', $agent->team_id)
                    ->whereIn('id', $deniedIds)
                    ->get();

                foreach ($tools as $tool) {
                    $deniedServerNames[] = $this->mcpConfigBuilder->serverName($tool);
                }
            }

            return self::build($workingDirectory, $mcpConfig, $allowShell, $deniedServerNames);
        } catch (\Throwable $e) {
            Log::warning('ClaudeCodeSettingsBuilder: failed to build agent permission settings', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return self::build($workingDirectory, $mcpConfig, $allowShell);
        }
    }

    /**
     * CLI arguments that apply the settings in dontAsk mode.
     *
     * @param  array<string, mixed>  $settings
     * @return list<string>
     */
    public static function cliArgs(array $settings): array
    {
        return ['--permission-mode', 'dontAsk', '--settings', self::encode($settings)];
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    public static function encode(array $settings): string
    {
        $json = json_encode($settings, JSON_UNESCAPED_SLASHES);

        return $json === false ? '{}' : $json;
    }
}
