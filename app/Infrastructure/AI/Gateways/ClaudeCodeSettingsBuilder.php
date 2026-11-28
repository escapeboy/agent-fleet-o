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
 *  - Lasting per-agent grants in agent.config.claude_code_permissions
 *    (`allow`/`deny` rule lists, written when a person approves a
 *    claude_code_permission proposal) are merged in. A deny grant is always
 *    added; an allow grant never lifts Bash(sudo...), a deny rule, a denied
 *    MCP server or allow_shell=false.
 */
final class ClaudeCodeSettingsBuilder
{
    public const GRANTS_CONFIG_KEY = 'claude_code_permissions';

    /** @var list<string> */
    private const ALWAYS_ALLOWED = ['Read', 'Glob', 'Grep'];

    /** @var list<string> */
    private const ALWAYS_DENIED = ['Bash(sudo:*)'];

    public function __construct(private readonly ClaudeCodeMcpConfigBuilder $mcpConfigBuilder) {}

    /**
     * Whether a rule may be stored and applied as a lasting allow grant:
     * a plain `Tool` or `Tool(specifier)` rule on one line, never a sudo
     * Bash rule and never an always-denied rule.
     */
    public static function isGrantableAllowRule(string $rule): bool
    {
        if (str_contains($rule, "\n") || str_contains($rule, "\r")) {
            return false;
        }
        if (preg_match('/^[A-Za-z][A-Za-z0-9_]*(\(.{1,300}\))?$/', $rule) !== 1) {
            return false;
        }
        if (preg_match('/^Bash\(\s*sudo\b/i', $rule) === 1) {
            return false;
        }

        return ! in_array($rule, self::ALWAYS_DENIED, true);
    }

    /**
     * @param  array<string, mixed>|null  $mcpConfig  `['mcpServers' => [...]]` or the bare server map
     * @param  array<int, string>  $deniedServerNames
     * @param  array<int, mixed>  $extraAllow  lasting allow grants of the agent
     * @param  array<int, mixed>  $extraDeny  lasting deny grants of the agent
     * @return array{permissions: array{allow: list<string>, deny: list<string>}}
     */
    public static function build(?string $workingDirectory, ?array $mcpConfig, bool $allowShell = true, array $deniedServerNames = [], array $extraAllow = [], array $extraDeny = []): array
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

        foreach ($extraDeny as $rule) {
            if (is_string($rule) && self::isWellFormedRule($rule)) {
                $deny[] = $rule;
            }
        }

        foreach ($extraAllow as $rule) {
            if (! is_string($rule) || ! self::isGrantableAllowRule($rule) || in_array($rule, $deny, true)) {
                continue;
            }
            if (! $allowShell && ($rule === 'Bash' || str_starts_with($rule, 'Bash('))) {
                continue;
            }
            foreach ($deniedRules as $deniedRule) {
                if ($rule === $deniedRule || str_starts_with($rule, $deniedRule.'__')) {
                    continue 2;
                }
            }
            $allow[] = $rule;
        }

        return [
            'permissions' => [
                'allow' => array_values(array_unique($allow)),
                'deny' => array_values(array_unique($deny)),
            ],
        ];
    }

    private static function isWellFormedRule(string $rule): bool
    {
        return ! str_contains($rule, "\n") && ! str_contains($rule, "\r")
            && preg_match('/^[A-Za-z][A-Za-z0-9_]*(\(.{1,300}\))?$/', $rule) === 1;
    }

    /**
     * The agent's stored grant list ('allow' or 'deny'), strings only.
     *
     * @param  array<mixed>  $config
     * @return list<string>
     */
    private static function grantList(array $config, string $kind): array
    {
        $grants = $config[self::GRANTS_CONFIG_KEY] ?? null;
        $list = is_array($grants) ? ($grants[$kind] ?? null) : null;
        if (! is_array($list)) {
            return [];
        }

        return array_values(array_filter($list, fn ($rule) => is_string($rule) && $rule !== ''));
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
        $extraAllow = self::grantList($config, 'allow');
        $extraDeny = self::grantList($config, 'deny');

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

            return self::build($workingDirectory, $mcpConfig, $allowShell, $deniedServerNames, $extraAllow, $extraDeny);
        } catch (\Throwable $e) {
            Log::warning('ClaudeCodeSettingsBuilder: failed to build agent permission settings', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return self::build($workingDirectory, $mcpConfig, $allowShell, [], $extraAllow, $extraDeny);
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
