<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Bridge\Models\BridgeConnection;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class LocalAgentDiscovery
{
    private ?array $bridgeCache = null;

    /**
     * Detect which local agents are available on the system.
     *
     * @return array<string, array{name: string, version: string, path: string}>
     */
    public function detect(): array
    {
        // Relay mode is a pure DB lookup against BridgeConnection.endpoints — no shell
        // execution. The local_agents.enabled guard targets the direct shell-exec path
        // (probe() below) which cloud edition forces off via CloudServiceProvider; it
        // must not gate the relay path or the admin UI cannot list agents.
        if ($this->isRelayMode()) {
            return $this->relayDiscover();
        }

        if (! config('local_agents.enabled')) {
            return [];
        }

        if ($this->shouldUseBridge()) {
            return $this->bridgeDiscover();
        }

        $agents = $this->registeredAgents();
        $detected = [];

        foreach ($agents as $key => $config) {
            $result = $this->probe($key, $config);

            if ($result) {
                $detected[$key] = $result;
            }
        }

        return $detected;
    }

    /**
     * Check if a specific agent is available.
     */
    public function isAvailable(string $agentKey): bool
    {
        if (! config('local_agents.enabled')) {
            return false;
        }

        $config = $this->agentConfig($agentKey);

        if (! $config) {
            return false;
        }

        if ($this->shouldUseBridge()) {
            $agents = $this->bridgeDiscover();

            return isset($agents[$agentKey]);
        }

        return $this->binaryPath($agentKey) !== null;
    }

    /**
     * Get the binary path for an agent.
     */
    public function binaryPath(string $agentKey): ?string
    {
        $config = $this->agentConfig($agentKey);

        if (! $config) {
            return null;
        }

        if ($this->shouldUseBridge()) {
            $agents = $this->bridgeDiscover();

            return isset($agents[$agentKey])
                ? ($agents[$agentKey]['path'] ?? "bridge://{$agentKey}")
                : null;
        }

        $binary = $config['binary'];

        try {
            $process = Process::fromShellCommandline("which {$binary}");
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                return trim($process->getOutput());
            }
        } catch (\Throwable $e) {
            Log::debug("LocalAgentDiscovery: failed to locate {$binary}", [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get the version string for an agent.
     */
    public function version(string $agentKey): ?string
    {
        $config = $this->agentConfig($agentKey);

        if (! $config) {
            return null;
        }

        if ($this->shouldUseBridge()) {
            $agents = $this->bridgeDiscover();

            return $agents[$agentKey]['version'] ?? null;
        }

        $detectCommand = $config['detect_command'];

        try {
            $process = Process::fromShellCommandline($detectCommand);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful()) {
                return $this->parseVersion($process->getOutput());
            }
        } catch (\Throwable $e) {
            Log::debug("LocalAgentDiscovery: version check failed for {$agentKey}", [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    /**
     * Get all agents with their config (for UI display).
     *
     * @return array<string, array{name: string, binary: string, description: string, capabilities: list<string>}>
     */
    public function allAgents(): array
    {
        return $this->registeredAgents();
    }

    /**
     * The full agent registry: built-in config entries plus any operator-registered
     * custom agents (stored in GlobalSetting). Built-ins win on key collision so a
     * custom entry can never shadow a shipped agent.
     *
     * @return array<string, array<string, mixed>>
     */
    public function registeredAgents(): array
    {
        return array_merge($this->customAgents(), config('local_agents.agents', []));
    }

    /**
     * Resolve a single agent's config from the merged registry.
     *
     * @return array<string, mixed>|null
     */
    public function agentConfig(string $agentKey): ?array
    {
        return $this->registeredAgents()[$agentKey] ?? null;
    }

    /**
     * Operator-registered custom local agents, normalised to the same shape as the
     * built-in registry. Read lazily (never at boot) and guarded so a missing
     * global_settings table on a fresh install degrades to "no custom agents".
     *
     * @return array<string, array<string, mixed>>
     */
    public function customAgents(): array
    {
        try {
            $raw = GlobalSetting::get('local_agents_custom', []);
        } catch (\Throwable) {
            return [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $key => $cfg) {
            if (! is_string($key) || ! is_array($cfg) || empty($cfg['binary']) || ! is_string($cfg['binary'])) {
                continue;
            }

            $executeFlags = array_values(array_filter((array) ($cfg['execute_flags'] ?? []), 'is_string'));

            $out[$key] = [
                'name' => (string) ($cfg['name'] ?? $key),
                'binary' => $cfg['binary'],
                'description' => (string) ($cfg['description'] ?? 'Custom local agent'),
                'detect_command' => (string) ($cfg['detect_command'] ?? ($cfg['binary'].' --version')),
                'requires_env' => isset($cfg['requires_env']) ? (string) $cfg['requires_env'] : null,
                'capabilities' => array_values(array_filter((array) ($cfg['capabilities'] ?? ['code_generation']), 'is_string')),
                'supported_modes' => array_values(array_filter((array) ($cfg['supported_modes'] ?? ['sync']), 'is_string')),
                'execute_flags' => $executeFlags,
                'stream_flags' => array_values(array_filter((array) ($cfg['stream_flags'] ?? $executeFlags), 'is_string')),
                'output_format' => (string) ($cfg['output_format'] ?? 'text'),
                'requires_pty' => (bool) ($cfg['requires_pty'] ?? false),
                'custom' => true,
            ];
        }

        return $out;
    }

    /**
     * Direct (bypass relay/bridge) detection for the VPS-installed Claude Code
     * binary. Returns the absolute binary path when found + valid, null otherwise.
     *
     * The regular detect()/binaryPath() flow short-circuits to the relay on hosts
     * that run fleetq-bridge as a relay (e.g. fleetq.net prod), so the VPS-local
     * claude would never be found through it. This method is the dedicated seam
     * for the super-admin-gated VPS provider.
     *
     * Deliberately NOT gated on `config('local_agents.enabled')`: the cloud
     * edition forces that global flag to false as a safety net against the
     * generic local-agent shell-execution path. The VPS path has its own gate
     * in ClaudeCodeVpsGate + the gateway-level assertAllowed() check.
     */
    public function vpsBinaryPath(): ?string
    {
        $configured = config('local_agents.vps.binary_path');
        if (is_string($configured) && $configured !== '') {
            return is_executable($configured) ? $configured : null;
        }

        try {
            $process = Process::fromShellCommandline('which claude');
            $process->setTimeout(5);
            $process->run();

            if ($process->isSuccessful()) {
                $path = trim($process->getOutput());

                return $path !== '' ? $path : null;
            }
        } catch (\Throwable $e) {
            Log::debug('LocalAgentDiscovery: vps claude probe failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    public function isVpsAgentAvailable(): bool
    {
        return $this->vpsBinaryPath() !== null;
    }

    /**
     * Whether the app is running inside Docker and should use the host bridge.
     */
    public function isBridgeMode(): bool
    {
        return $this->shouldUseBridge();
    }

    /**
     * Whether the relay service is enabled (RELAY_ENABLED=true in .env).
     * In relay mode, bridge status is tracked via BridgeConnection model, not HTTP polls.
     */
    public function isRelayMode(): bool
    {
        return (bool) config('bridge.relay_enabled', false);
    }

    /**
     * Whether we're in Docker but LOCAL_AGENT_BRIDGE_SECRET is not configured.
     * In this state the bridge cannot be used even if the daemon is running.
     */
    public function needsBridgeConfig(): bool
    {
        // In relay mode the secret is not needed — fleetq-bridge authenticates via Sanctum
        if ($this->isRelayMode()) {
            return false;
        }

        return $this->isRunningInDocker()
            && empty(config('local_agents.bridge.secret'));
    }

    /**
     * Get the bridge base URL.
     */
    public function bridgeUrl(): string
    {
        return config('local_agents.bridge.url', 'http://host.docker.internal:8065');
    }

    /**
     * Get the bridge auth secret.
     */
    public function bridgeSecret(): string
    {
        return config('local_agents.bridge.secret', '');
    }

    /**
     * Check if the bridge server is reachable.
     * In relay mode, checks the BridgeConnection model instead of polling HTTP.
     */
    public function bridgeHealth(): bool
    {
        if ($this->isRelayMode()) {
            // Relay mode: check for an active BridgeConnection in the database
            try {
                return BridgeConnection::active()->exists();
            } catch (\Throwable $e) {
                Log::debug('LocalAgentDiscovery: relay bridge health check failed', [
                    'error' => $e->getMessage(),
                ]);

                return false;
            }
        }

        $cacheKey = 'local_agents.bridge_health';
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return (bool) $cached;
        }

        try {
            $response = Http::timeout(config('local_agents.bridge.connect_timeout', 5))
                ->get($this->bridgeUrl().'/health');

            $healthy = $response->successful() && ($response->json('status') === 'ok');
            Cache::put($cacheKey, $healthy, 15);

            return $healthy;
        } catch (\Throwable $e) {
            Log::debug('LocalAgentDiscovery: bridge health check failed', [
                'error' => $e->getMessage(),
            ]);
            Cache::put($cacheKey, false, 15);

            return false;
        }
    }

    /**
     * Determine if we're running inside Docker.
     */
    private function isRunningInDocker(): bool
    {
        $env = env('RUNNING_IN_DOCKER');

        // Explicit override takes precedence (supports both true and false)
        if ($env !== null) {
            return filter_var($env, FILTER_VALIDATE_BOOLEAN);
        }

        return file_exists('/.dockerenv');
    }

    /**
     * Determine if the bridge should be used instead of direct binary detection.
     */
    private function shouldUseBridge(): bool
    {
        return $this->isRunningInDocker()
            && config('local_agents.bridge.auto_detect', true)
            && ! empty(config('local_agents.bridge.secret'));
    }

    /**
     * Maps bridge daemon agent keys to local_agents config keys where they differ.
     */
    private const BRIDGE_KEY_MAP = [
        'gemini' => 'gemini-cli',
    ];

    /**
     * Discover agents from the active BridgeConnection in relay mode.
     *
     * @return array<string, array{name: string, version: string, path: string}>
     */
    private function relayDiscover(): array
    {
        if ($this->bridgeCache !== null) {
            return $this->bridgeCache;
        }

        try {
            // Aggregate agents from ALL active bridge connections (TeamScope auto-filters).
            $connections = BridgeConnection::active()
                ->orderByDesc('priority')
                ->orderByDesc('connected_at')
                ->get();

            if ($connections->isEmpty()) {
                $this->bridgeCache = [];

                return [];
            }

            $detected = [];
            foreach ($connections as $connection) {
                foreach ($connection->agents() as $agent) {
                    if (! ($agent['found'] ?? false)) {
                        continue;
                    }
                    $bridgeKey = $agent['key'];
                    $configKey = self::BRIDGE_KEY_MAP[$bridgeKey] ?? $bridgeKey;

                    // First bridge with this agent wins (highest priority / most recent)
                    if (isset($detected[$configKey])) {
                        continue;
                    }

                    $detected[$configKey] = [
                        'name' => $agent['name'],
                        'version' => $this->parseVersion($agent['version'] ?? ''),
                        'path' => "bridge://{$connection->id}:{$bridgeKey}",
                        'bridge_id' => $connection->id,
                    ];
                }
            }

            $this->bridgeCache = $detected;
        } catch (\Throwable $e) {
            Log::debug('LocalAgentDiscovery: relay discover failed', [
                'error' => $e->getMessage(),
            ]);

            $this->bridgeCache = [];
        }

        return $this->bridgeCache;
    }

    /**
     * Discover agents via the host bridge HTTP server.
     *
     * @return array<string, array{name: string, version: string, path: string}>
     */
    private function bridgeDiscover(): array
    {
        if ($this->bridgeCache !== null) {
            return $this->bridgeCache;
        }

        $cacheKey = 'local_agents.bridge_discover';
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            $this->bridgeCache = $cached;

            return $cached;
        }

        try {
            $response = Http::timeout(config('local_agents.bridge.connect_timeout', 5))
                ->withToken($this->bridgeSecret())
                ->get($this->bridgeUrl().'/discover');

            if ($response->successful()) {
                $agents = $response->json('agents') ?? [];
                Cache::put($cacheKey, $agents, 30);
                $this->bridgeCache = $agents;

                return $agents;
            }

            Log::warning('LocalAgentDiscovery: bridge discover failed', [
                'status' => $response->status(),
            ]);
            // Invalidate health cache so the next bridgeHealth() call re-checks
            Cache::forget('local_agents.bridge_health');
        } catch (\Throwable $e) {
            Log::debug('LocalAgentDiscovery: bridge connection failed', [
                'error' => $e->getMessage(),
            ]);
            Cache::forget('local_agents.bridge_health');
        }

        $this->bridgeCache = [];

        return [];
    }

    /**
     * Probe a single agent for availability.
     *
     * @return array{name: string, version: string, path: string}|null
     */
    private function probe(string $key, array $config): ?array
    {
        $path = $this->binaryPath($key);

        if (! $path) {
            return null;
        }

        // Run the detect command and capture raw output for both version parsing
        // and identity verification (e.g. collision detection for generic binary names).
        $detectCommand = $config['detect_command'];
        $rawOutput = '';

        try {
            $process = Process::fromShellCommandline($detectCommand);
            $process->setTimeout(10);
            $process->run();

            if ($process->isSuccessful()) {
                $rawOutput = $process->getOutput();
            }
        } catch (\Throwable $e) {
            Log::debug("LocalAgentDiscovery: detect command failed for {$key}", [
                'error' => $e->getMessage(),
            ]);
        }

        if (! $rawOutput) {
            return null;
        }

        // The Cursor CLI binary is named 'agent' — a generic name that other tools may also use.
        // Verify that the detected binary is actually Cursor's CLI by checking that its
        // raw version output contains the word "cursor" (case-insensitive).
        // NOTE: We check rawOutput, not the parsed version number, because parseVersion()
        // strips the identifier prefix and returns only the numeric part (e.g. "0.48.1").
        if ($key === 'cursor' && ! str_contains(strtolower($rawOutput), 'cursor')) {
            Log::debug('LocalAgentDiscovery: agent binary found but does not identify as Cursor CLI', [
                'path' => $path,
                'raw_output' => trim($rawOutput),
            ]);

            return null;
        }

        $version = $this->parseVersion($rawOutput);

        return [
            'name' => $config['name'],
            'version' => $version,
            'path' => $path,
        ];
    }

    /**
     * Extract version number from command output.
     */
    private function parseVersion(string $output): string
    {
        $output = trim($output);

        // Match common version patterns: v1.2.3, 1.2.3, etc.
        if (preg_match('/v?(\d+\.\d+(?:\.\d+)?(?:[.-]\w+)?)/', $output, $matches)) {
            return $matches[1];
        }

        // Fallback: return first line trimmed
        $firstLine = strtok($output, "\n");

        return $firstLine ?: $output;
    }
}
