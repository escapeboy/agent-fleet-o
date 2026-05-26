<?php

namespace App\Infrastructure\AI\Services;

use App\Infrastructure\AI\DTOs\AiRequestDTO;

/**
 * Rewrites a claude-code-vps run to route its outbound credentials through the
 * secret-proxy daemon. It mints a run token, stores the real secrets in the
 * vault, and swaps the agent's env + .claude.json so the spawned `claude`
 * process holds only the opaque token — `env` and `cat ~/.claude.json` no longer
 * expose the Anthropic OAuth token or any MCP bearer.
 */
class SecretProxyInjector
{
    public function __construct(
        private readonly RunSecretVault $vault,
        private readonly EgressAllowlist $allowlist,
    ) {}

    /**
     * The feature only engages when the flag is on AND it is fully configured —
     * a half-configured proxy must never silently strip the real token and
     * leave the agent unable to authenticate.
     */
    public static function enabled(): bool
    {
        return (bool) config('secret_proxy.enabled')
            && (string) config('secret_proxy.base_url') !== ''
            && (string) config('secret_proxy.key') !== '';
    }

    /**
     * @param  array<string, string>  $env  Modified in place (OAuth token + ANTHROPIC_BASE_URL).
     * @param  array{mcpServers?: array<string, array<string, mixed>>}|null  $realMcp  The real MCP config (assistant runs pass null).
     * @return string The opaque run token — caller MUST revoke it when the run ends.
     */
    public function apply(AiRequestDTO $request, string $workdir, array &$env, string $oauthToken, int $timeout, ?array $realMcp): string
    {
        $base = rtrim((string) config('secret_proxy.base_url'), '/');

        $hosts = [];
        $mcpBundle = [];
        $proxyServers = [];
        $httpRefs = [];

        if ($oauthToken !== '') {
            $hosts[] = 'api.anthropic.com';
        }

        foreach (($realMcp['mcpServers'] ?? []) as $name => $entry) {
            if (($entry['type'] ?? null) === 'http' && isset($entry['url']) && is_string($entry['url'])) {
                $realUrl = $entry['url'];
                $auth = $entry['headers']['Authorization'] ?? '';
                $host = parse_url($realUrl, PHP_URL_HOST);

                // Always strip the real auth and point the entry at the proxy so
                // no real credential lands in .claude.json.
                $entry['url'] = $base.'/egress/mcp/'.$name;
                unset($entry['headers']['Authorization']);
                $proxyServers[$name] = $entry;

                // Only register the upstream (with its secret) + allowlist host
                // when the host is parseable. An unparseable host means no bundle
                // entry → the daemon 404s the ref (fail closed); the real secret
                // was already stripped above either way.
                if (is_string($host) && $host !== '') {
                    $hosts[] = $host;
                    $mcpBundle[$name] = ['url' => $realUrl, 'auth' => is_string($auth) ? $auth : ''];
                    $httpRefs[] = $name;
                }
            } else {
                // stdio / non-http servers pass through unchanged (Phase 1 limitation:
                // a forward proxy can't strip secrets from a spawned subprocess env).
                $proxyServers[$name] = $entry;
            }
        }

        $bundle = [
            'anthropic_oauth' => $oauthToken !== '' ? $oauthToken : null,
            'mcp' => $mcpBundle,
            'allowed_hosts' => $this->allowlist->forRun($request, $hosts),
        ];

        $ttl = $timeout + (int) config('secret_proxy.vault_ttl_margin', 120);
        $token = $this->vault->issue($bundle, $ttl);

        foreach ($httpRefs as $name) {
            $proxyServers[$name]['headers']['Authorization'] = 'Bearer '.$token;
        }

        $env['CLAUDE_CODE_OAUTH_TOKEN'] = $token;
        if ($oauthToken !== '') {
            $env['ANTHROPIC_BASE_URL'] = $base.'/egress/anthropic';
        }

        if ($proxyServers !== []) {
            $this->writeClaudeJson($workdir, ['mcpServers' => $proxyServers]);
        }

        return $token;
    }

    /**
     * @param  array{mcpServers: array<string, array<string, mixed>>}  $config
     */
    private function writeClaudeJson(string $workdir, array $config): void
    {
        $path = $workdir.'/.claude.json';
        @file_put_contents($path, json_encode($config, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($path, 0600);
    }
}
