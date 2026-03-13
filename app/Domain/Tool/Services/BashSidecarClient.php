<?php

namespace App\Domain\Tool\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * HTTP client for the just-bash Docker sidecar.
 *
 * The sidecar exposes a small HTTP API that executes bash commands inside
 * an in-memory virtual filesystem (just-bash). One Bash instance is kept
 * alive per session for the duration of an agent execution.
 */
class BashSidecarClient
{
    private readonly string $baseUrl;
    private readonly string $secret;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('agent.bash_sidecar_url', 'http://bash_sidecar:3001'), '/');
        $this->secret = config('agent.bash_sidecar_secret', '');
    }

    /**
     * Create a new bash session on the sidecar.
     *
     * @throws \RuntimeException when the sidecar rejects the request or is unreachable
     */
    public function createSession(string $sessionId): void
    {
        try {
            $response = $this->client()->post('/session', ['sessionId' => $sessionId]);

            if ($response->status() === 429) {
                throw new \RuntimeException('Bash sidecar session limit exceeded. Try again later.');
            }

            if (! $response->successful()) {
                throw new \RuntimeException("Bash sidecar error: {$response->status()}");
            }
        } catch (ConnectionException $e) {
            throw new \RuntimeException('Bash sandbox is unavailable. Please try again later.', 0, $e);
        }
    }

    /**
     * Run a bash command in the given session.
     *
     * @return array{stdout: string, stderr: string, exitCode: int}
     */
    public function run(string $sessionId, string $command, int $timeoutMs = 30_000): array
    {
        // HTTP client timeout must exceed the command timeout so the response has time to arrive.
        $httpTimeout = (int) ceil($timeoutMs / 1000) + 10;

        try {
            $response = Http::timeout($httpTimeout)
                ->baseUrl($this->baseUrl)
                ->withToken($this->secret)
                ->post('/exec', [
                    'sessionId' => $sessionId,
                    'command'   => $command,
                    'timeoutMs' => $timeoutMs,
                ]);

            if ($response->status() === 408) {
                return ['stdout' => '', 'stderr' => 'Command timed out', 'exitCode' => 124];
            }

            if ($response->status() === 429) {
                return ['stdout' => '', 'stderr' => 'Bash sandbox session limit exceeded', 'exitCode' => 1];
            }

            if (! $response->successful()) {
                return ['stdout' => '', 'stderr' => "Bash sandbox error: {$response->status()}", 'exitCode' => 1];
            }

            $body = $response->json();

            return [
                'stdout'   => $body['stdout'] ?? '',
                'stderr'   => $body['stderr'] ?? '',
                'exitCode' => $body['exitCode'] ?? 0,
            ];
        } catch (ConnectionException) {
            return ['stdout' => '', 'stderr' => 'Bash sandbox unavailable', 'exitCode' => 1];
        }
    }

    /**
     * Destroy a session. Best-effort — errors are silently ignored.
     */
    public function destroySession(string $sessionId): void
    {
        try {
            $this->client()->delete("/session/{$sessionId}");
        } catch (\Throwable) {
            // Best-effort cleanup — sidecar will GC idle sessions automatically
        }
    }

    /**
     * Ping the sidecar health endpoint.
     */
    public function ping(): bool
    {
        try {
            $response = Http::timeout(3)->get("{$this->baseUrl}/health");

            return $response->ok() && ($response->json('ok') === true);
        } catch (\Throwable) {
            return false;
        }
    }

    private function client(): \Illuminate\Http\Client\PendingRequest
    {
        return Http::timeout(5)
            ->baseUrl($this->baseUrl)
            ->withToken($this->secret);
    }
}
