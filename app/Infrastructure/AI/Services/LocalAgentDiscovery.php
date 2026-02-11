<?php

namespace App\Infrastructure\AI\Services;

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
        if (! config('local_agents.enabled')) {
            return [];
        }

        if ($this->shouldUseBridge()) {
            return $this->bridgeDiscover();
        }

        $agents = config('local_agents.agents', []);
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

        $config = config("local_agents.agents.{$agentKey}");

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
        $config = config("local_agents.agents.{$agentKey}");

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

        $process = Process::fromShellCommandline("which {$binary}");
        $process->setTimeout(5);

        try {
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
        $config = config("local_agents.agents.{$agentKey}");

        if (! $config) {
            return null;
        }

        if ($this->shouldUseBridge()) {
            $agents = $this->bridgeDiscover();

            return $agents[$agentKey]['version'] ?? null;
        }

        $detectCommand = $config['detect_command'];

        $process = Process::fromShellCommandline($detectCommand);
        $process->setTimeout(10);

        try {
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
        return config('local_agents.agents', []);
    }

    /**
     * Whether the app is running inside Docker and should use the host bridge.
     */
    public function isBridgeMode(): bool
    {
        return $this->shouldUseBridge();
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
     */
    public function bridgeHealth(): bool
    {
        try {
            $response = Http::timeout(config('local_agents.bridge.connect_timeout', 5))
                ->get($this->bridgeUrl() . '/health');

            return $response->successful() && ($response->json('status') === 'ok');
        } catch (\Throwable $e) {
            Log::debug('LocalAgentDiscovery: bridge health check failed', [
                'error' => $e->getMessage(),
            ]);

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
     * Discover agents via the host bridge HTTP server.
     *
     * @return array<string, array{name: string, version: string, path: string}>
     */
    private function bridgeDiscover(): array
    {
        if ($this->bridgeCache !== null) {
            return $this->bridgeCache;
        }

        try {
            $response = Http::timeout(config('local_agents.bridge.connect_timeout', 5))
                ->withToken($this->bridgeSecret())
                ->get($this->bridgeUrl() . '/discover');

            if ($response->successful()) {
                $this->bridgeCache = $response->json('agents') ?? [];

                return $this->bridgeCache;
            }

            Log::warning('LocalAgentDiscovery: bridge discover failed', [
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            Log::debug('LocalAgentDiscovery: bridge connection failed', [
                'error' => $e->getMessage(),
            ]);
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

        $version = $this->version($key) ?? 'unknown';

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
