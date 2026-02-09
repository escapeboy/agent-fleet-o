<?php

namespace App\Infrastructure\AI\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

class LocalAgentDiscovery
{
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
