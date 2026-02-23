<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Enums\ToolType;
use Illuminate\Support\Str;

class McpConfigNormalizer
{
    private const SAFE_COMMANDS = [
        'npx', 'node', 'python', 'python3', 'uvx', 'uv', 'docker',
        'deno', 'bun', 'php', 'ruby', 'go', 'cargo', 'pipx',
    ];

    private const CREDENTIAL_ENV_PATTERNS = [
        'API_KEY', 'SECRET', 'TOKEN', 'PASSWORD', 'AUTH',
        'PRIVATE_KEY', 'ACCESS_KEY', 'CLIENT_SECRET',
    ];

    /**
     * Normalize a raw MCP server config into a standard format.
     *
     * @return array{name: string, slug: string, source: string, type: string, transport_config: array, credentials: array, disabled: bool, warnings: array}
     */
    public function normalize(string $serverName, array $rawConfig, string $source): array
    {
        $type = $this->classifyType($rawConfig);
        $transportConfig = $this->extractTransportConfig($rawConfig, $type);
        $credentials = $this->extractCredentials($rawConfig);
        $warnings = $this->validateConfig($rawConfig, $type);

        return [
            'name' => $this->humanizeName($serverName),
            'slug' => $this->generateSlug($serverName, $source),
            'source' => $source,
            'type' => $type->value,
            'transport_config' => $transportConfig,
            'credentials' => $credentials,
            'disabled' => (bool) ($rawConfig['disabled'] ?? false),
            'warnings' => $warnings,
        ];
    }

    public function classifyType(array $rawConfig): ToolType
    {
        if (isset($rawConfig['url'])) {
            return ToolType::McpHttp;
        }

        if (isset($rawConfig['type']) && $rawConfig['type'] === 'sse') {
            return ToolType::McpHttp;
        }

        if (isset($rawConfig['command'])) {
            return ToolType::McpStdio;
        }

        // Default to stdio for unknown formats
        return ToolType::McpStdio;
    }

    public function extractTransportConfig(array $rawConfig, ?ToolType $type = null): array
    {
        $type ??= $this->classifyType($rawConfig);

        if ($type === ToolType::McpHttp) {
            $config = ['url' => $rawConfig['url'] ?? ''];

            // Extract headers but strip auth headers (those go to credentials)
            $headers = $rawConfig['headers'] ?? [];
            $safeHeaders = [];
            foreach ($headers as $key => $value) {
                if (! $this->isAuthHeader($key)) {
                    $safeHeaders[$key] = $value;
                }
            }

            if (! empty($safeHeaders)) {
                $config['headers'] = $safeHeaders;
            }

            return $config;
        }

        // stdio
        $config = [
            'command' => $rawConfig['command'] ?? '',
            'args' => $rawConfig['args'] ?? [],
        ];

        // Include cwd if present
        if (isset($rawConfig['cwd'])) {
            $config['cwd'] = $rawConfig['cwd'];
        }

        return $config;
    }

    public function extractCredentials(array $rawConfig): array
    {
        $credentials = [];

        // Extract auth headers
        $headers = $rawConfig['headers'] ?? [];
        foreach ($headers as $key => $value) {
            if ($this->isAuthHeader($key)) {
                $credentials[$this->normalizeCredentialKey($key)] = $value;
            }
        }

        // Extract sensitive env vars
        $env = $rawConfig['env'] ?? [];
        foreach ($env as $key => $value) {
            if ($this->isSensitiveEnvVar($key)) {
                $credentials["env_{$key}"] = $value;
            }
        }

        return $credentials;
    }

    public function generateSlug(string $name, string $source): string
    {
        $slug = Str::slug($name);

        // Append source to prevent cross-IDE collisions
        $sourceSlug = Str::slug($source);

        if ($sourceSlug && $sourceSlug !== $slug) {
            return "{$slug}-{$sourceSlug}";
        }

        return $slug;
    }

    /**
     * Parse a JSON string into MCP server configs.
     *
     * Accepts either a full config file (with mcpServers/servers key) or a direct servers object.
     *
     * @return array<string, array> Server name => raw config
     */
    public function parseJsonInput(string $json): array
    {
        $data = json_decode($json, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return [];
        }

        // Try known keys
        foreach (['mcpServers', 'servers'] as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        // Check if the input itself is a servers object (each value has command or url)
        if ($this->looksLikeServersObject($data)) {
            return $data;
        }

        return [];
    }

    /**
     * Validate an HTTP URL for SSRF safety.
     */
    public function isUrlSafe(string $url): bool
    {
        $parsed = parse_url($url);

        if (! $parsed || ! isset($parsed['scheme'], $parsed['host'])) {
            return false;
        }

        // Only allow http(s)
        if (! in_array($parsed['scheme'], ['http', 'https'], true)) {
            return false;
        }

        $host = $parsed['host'];

        // Reject private IPs
        $ip = gethostbyname($host);
        if ($ip !== $host) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false;
            }
        }

        // Reject localhost variants
        if (in_array(strtolower($host), ['localhost', '0.0.0.0', '[::]'], true)) {
            return false;
        }

        return true;
    }

    /**
     * Check if a command is in the safe allowlist.
     */
    public function isCommandSafe(string $command): bool
    {
        $binary = basename($command);

        return in_array($binary, self::SAFE_COMMANDS, true);
    }

    private function humanizeName(string $name): string
    {
        // Convert kebab-case/snake_case to Title Case
        return Str::title(str_replace(['-', '_'], ' ', $name));
    }

    private function isAuthHeader(string $headerName): bool
    {
        $lower = strtolower($headerName);

        return in_array($lower, ['authorization', 'x-api-key', 'x-auth-token'], true);
    }

    private function normalizeCredentialKey(string $headerName): string
    {
        return match (strtolower($headerName)) {
            'authorization' => 'api_key',
            'x-api-key' => 'api_key',
            'x-auth-token' => 'auth_token',
            default => Str::snake($headerName),
        };
    }

    private function isSensitiveEnvVar(string $key): bool
    {
        $upper = strtoupper($key);

        foreach (self::CREDENTIAL_ENV_PATTERNS as $pattern) {
            if (str_contains($upper, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function validateConfig(array $rawConfig, ToolType $type): array
    {
        $warnings = [];

        if ($type === ToolType::McpHttp) {
            $url = $rawConfig['url'] ?? '';
            if ($url && ! $this->isUrlSafe($url)) {
                $warnings[] = 'URL points to a private/local address and may not be reachable.';
            }
        }

        if ($type === ToolType::McpStdio) {
            $command = $rawConfig['command'] ?? '';
            if ($command && ! $this->isCommandSafe($command)) {
                $warnings[] = "Command '{$command}' is not in the safe allowlist. Review before enabling.";
            }
        }

        return $warnings;
    }

    private function looksLikeServersObject(array $data): bool
    {
        if (empty($data)) {
            return false;
        }

        foreach ($data as $key => $value) {
            if (! is_string($key) || ! is_array($value)) {
                return false;
            }

            if (isset($value['command']) || isset($value['url'])) {
                return true;
            }
        }

        return false;
    }
}
