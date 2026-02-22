<?php

namespace App\Domain\Tool\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class McpConfigDiscovery
{
    private const CONFIG_SOURCES = [
        'claude_desktop' => [
            'label' => 'Claude Desktop',
            'paths' => [
                'darwin' => 'Library/Application Support/Claude/claude_desktop_config.json',
                'linux' => '.config/Claude/claude_desktop_config.json',
                'win' => 'AppData/Roaming/Claude/claude_desktop_config.json',
            ],
            'key' => 'mcpServers',
        ],
        'claude_code' => [
            'label' => 'Claude Code',
            'paths' => ['all' => '.claude.json'],
            'key' => 'mcpServers',
        ],
        'cursor' => [
            'label' => 'Cursor',
            'paths' => ['all' => '.cursor/mcp.json'],
            'key' => 'mcpServers',
        ],
        'windsurf' => [
            'label' => 'Windsurf',
            'paths' => ['all' => '.codeium/windsurf/mcp_config.json'],
            'key' => 'mcpServers',
        ],
        'kiro' => [
            'label' => 'Kiro',
            'paths' => ['all' => '.kiro/settings/mcp.json'],
            'key' => 'mcpServers',
        ],
        'vscode' => [
            'label' => 'VS Code',
            'paths' => ['all' => '.vscode/mcp.json'],
            'key' => 'servers',
        ],
    ];

    public function __construct(
        private McpConfigNormalizer $normalizer,
    ) {}

    /**
     * Scan all known IDE config sources for MCP servers.
     *
     * @return array{sources: array<string, array{label: string, file: string, count: int}>, servers: array}
     */
    public function scanAllSources(): array
    {
        if ($this->shouldUseBridge()) {
            return $this->bridgeScan();
        }

        $allServers = [];
        $sourceSummary = [];

        foreach (self::CONFIG_SOURCES as $sourceKey => $sourceConfig) {
            $result = $this->scanSource($sourceKey);

            if (! empty($result['servers'])) {
                $sourceSummary[$sourceKey] = [
                    'label' => $sourceConfig['label'],
                    'file' => $result['file'] ?? '',
                    'count' => count($result['servers']),
                ];

                $allServers = array_merge($allServers, $result['servers']);
            }
        }

        return [
            'sources' => $sourceSummary,
            'servers' => $allServers,
        ];
    }

    /**
     * Scan a specific IDE source for MCP servers.
     *
     * @return array{file: string|null, servers: array}
     */
    public function scanSource(string $sourceKey): array
    {
        $sourceConfig = self::CONFIG_SOURCES[$sourceKey] ?? null;

        if (! $sourceConfig) {
            return ['file' => null, 'servers' => []];
        }

        $filePath = $this->resolveConfigPath($sourceConfig['paths']);

        if (! $filePath || ! file_exists($filePath)) {
            return ['file' => $filePath, 'servers' => []];
        }

        return [
            'file' => $filePath,
            'servers' => $this->parseConfigFile($filePath, $sourceConfig['key'], $sourceConfig['label']),
        ];
    }

    /**
     * Parse a config file and extract normalized server configs.
     */
    public function parseConfigFile(string $path, string $serverKey, string $sourceLabel): array
    {
        $content = @file_get_contents($path);

        if ($content === false) {
            Log::debug("McpConfigDiscovery: failed to read {$path}");

            return [];
        }

        $data = json_decode($content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Log::debug("McpConfigDiscovery: invalid JSON in {$path}");

            return [];
        }

        $servers = $data[$serverKey] ?? [];

        if (! is_array($servers)) {
            return [];
        }

        $normalized = [];
        foreach ($servers as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $normalized[] = $this->normalizer->normalize($name, $config, $sourceLabel);
        }

        return $normalized;
    }

    /**
     * Parse raw JSON input (from paste or upload).
     */
    public function parseJsonInput(string $json, string $sourceLabel = 'Manual Import'): array
    {
        $servers = $this->normalizer->parseJsonInput($json);

        $normalized = [];
        foreach ($servers as $name => $config) {
            if (! is_array($config)) {
                continue;
            }

            $normalized[] = $this->normalizer->normalize($name, $config, $sourceLabel);
        }

        return $normalized;
    }

    /**
     * Get list of available (existing) config sources on the system.
     *
     * @return array<string, array{label: string, path: string}>
     */
    public function availableSources(): array
    {
        $available = [];

        foreach (self::CONFIG_SOURCES as $key => $config) {
            $path = $this->resolveConfigPath($config['paths']);

            if ($path && file_exists($path)) {
                $available[$key] = [
                    'label' => $config['label'],
                    'path' => $path,
                ];
            }
        }

        return $available;
    }

    /**
     * Get all known source labels (even if config files don't exist).
     *
     * @return array<string, string> key => label
     */
    public function allSourceLabels(): array
    {
        $labels = [];

        foreach (self::CONFIG_SOURCES as $key => $config) {
            $labels[$key] = $config['label'];
        }

        return $labels;
    }

    /**
     * Whether the discovery is using bridge mode (Docker).
     */
    public function isBridgeMode(): bool
    {
        return $this->shouldUseBridge();
    }

    /**
     * Resolve the config file path for the current OS.
     */
    private function resolveConfigPath(array $paths): ?string
    {
        $home = $this->getHomeDirectory();

        if (! $home) {
            return null;
        }

        // Check OS-specific paths first
        $os = $this->detectOS();
        if (isset($paths[$os])) {
            return $home.DIRECTORY_SEPARATOR.$paths[$os];
        }

        // Fall back to 'all' key
        if (isset($paths['all'])) {
            return $home.DIRECTORY_SEPARATOR.$paths['all'];
        }

        return null;
    }

    private function getHomeDirectory(): ?string
    {
        return $_SERVER['HOME'] ?? $_ENV['HOME'] ?? getenv('HOME') ?: null;
    }

    private function detectOS(): string
    {
        return match (PHP_OS_FAMILY) {
            'Darwin' => 'darwin',
            'Windows' => 'win',
            default => 'linux',
        };
    }

    private function shouldUseBridge(): bool
    {
        return $this->isRunningInDocker()
            && config('local_agents.bridge.auto_detect', true)
            && ! empty(config('local_agents.bridge.secret'));
    }

    private function isRunningInDocker(): bool
    {
        $env = env('RUNNING_IN_DOCKER');

        if ($env !== null) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return file_exists('/.dockerenv');
    }

    /**
     * Discover MCP configs via the host bridge.
     */
    private function bridgeScan(): array
    {
        $bridgeUrl = config('local_agents.bridge.url', 'http://host.docker.internal:8065');
        $bridgeSecret = config('local_agents.bridge.secret', '');
        $timeout = config('local_agents.bridge.connect_timeout', 5);

        try {
            $response = Http::timeout($timeout)
                ->withToken($bridgeSecret)
                ->get("{$bridgeUrl}/mcp-configs");

            if ($response->successful()) {
                $data = $response->json();

                return [
                    'sources' => $data['sources'] ?? [],
                    'servers' => $data['servers'] ?? [],
                ];
            }

            Log::warning('McpConfigDiscovery: bridge mcp-configs request failed', [
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('McpConfigDiscovery: bridge connection failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return ['sources' => [], 'servers' => []];
    }
}
