<?php

namespace App\Domain\Agent\Services\Sandbox;

use App\Domain\Agent\Contracts\SandboxDriverInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Modal Sandboxes-backed driver.
 *
 * Delegates execution to a Modal deployment (see modal/app.py) that provisions
 * a fresh gVisor-isolated Sandbox per call, mounts workspace files, runs the
 * command with hard timeout + mem/CPU caps, then terminates. The Modal HTTPS
 * endpoint is authenticated with a shared bearer token stored as a Modal Secret.
 *
 * The Modal path is intentionally *not* the default. Enable per-environment via
 * SANDBOX_DRIVER=modal.
 */
final class ModalSandboxDriver implements SandboxDriverInterface
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $endpointUrl,
        private readonly string $endpointToken,
        private readonly string $defaultImage = 'python:3.12-slim',
        private readonly string $defaultRegion = 'eu',
        private readonly int $connectTimeoutSeconds = 15,
        private readonly int $requestOverheadSeconds = 30,
    ) {}

    public function execute(string $worktreePath, string|array $command, array $sandboxConfig = []): array
    {
        if ($this->endpointUrl === '' || $this->endpointToken === '') {
            throw new RuntimeException(
                'ModalSandboxDriver requires MODAL_ENDPOINT_URL and MODAL_ENDPOINT_TOKEN.',
            );
        }

        $image = $sandboxConfig['image'] ?? $this->defaultImage;
        $memoryLimit = $sandboxConfig['memory_limit'] ?? '512m';
        $cpuLimit = (string) ($sandboxConfig['cpu_limit'] ?? '1');
        $timeoutSeconds = (int) ($sandboxConfig['timeout_seconds'] ?? 300);
        $env = $sandboxConfig['env'] ?? [];
        $region = $sandboxConfig['region'] ?? $this->defaultRegion;
        $correlationId = (string) Str::uuid();

        $payload = [
            'correlation_id' => $correlationId,
            'image' => $image,
            'memory_mb' => $this->parseMemoryMb($memoryLimit),
            'cpu' => (float) $cpuLimit,
            'timeout_seconds' => $timeoutSeconds,
            'region' => $region,
            'env' => $this->sanitizeEnv($env),
            'command' => is_array($command) ? $command : ['/bin/sh', '-c', $command],
            'workspace' => $this->collectWorkspace($worktreePath),
        ];

        $startedAt = microtime(true);

        try {
            $response = $this->http
                ->baseUrl($this->endpointUrl)
                ->withToken($this->endpointToken)
                ->withHeaders([
                    'X-Correlation-Id' => $correlationId,
                    'Accept' => 'application/json',
                ])
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($timeoutSeconds + $this->requestOverheadSeconds)
                ->retry(2, 500, function ($exception) {
                    return $exception instanceof ConnectionException;
                }, throw: false)
                ->post('/execute', $payload);
        } catch (ConnectionException $e) {
            Log::error('ModalSandboxDriver: connection failure', [
                'correlation_id' => $correlationId,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Modal sandbox unreachable: '.$e->getMessage(), 0, $e);
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        if (! $response->successful()) {
            Log::error('ModalSandboxDriver: non-2xx response', [
                'correlation_id' => $correlationId,
                'status' => $response->status(),
                'body' => mb_substr($response->body(), 0, 1024),
            ]);
            throw new RuntimeException(
                "Modal sandbox HTTP {$response->status()}: ".mb_substr($response->body(), 0, 512),
            );
        }

        $body = (array) $response->json();

        $result = [
            'exit_code' => $body['exit_code'] ?? null,
            'stdout' => mb_substr((string) ($body['stdout'] ?? ''), 0, 65_535),
            'stderr' => mb_substr((string) ($body['stderr'] ?? ''), 0, 16_384),
            'driver' => $this->name(),
            'correlation_id' => $correlationId,
            'duration_ms' => $durationMs,
        ];

        if (isset($body['image_digest'])) {
            $result['image_digest'] = (string) $body['image_digest'];
        }
        if (isset($body['cost_estimate_usd'])) {
            $result['cost_estimate_usd'] = (float) $body['cost_estimate_usd'];
        }
        if (! empty($body['artifacts']) && is_array($body['artifacts'])) {
            $result['artifacts'] = $body['artifacts'];
        }

        Log::info('ModalSandboxDriver: execution complete', [
            'correlation_id' => $correlationId,
            'image' => $image,
            'exit_code' => $result['exit_code'],
            'duration_ms' => $durationMs,
            'timeout_seconds' => $timeoutSeconds,
            'image_digest' => $result['image_digest'] ?? null,
            'cost_estimate_usd' => $result['cost_estimate_usd'] ?? null,
        ]);

        return $result;
    }

    public function name(): string
    {
        return 'modal';
    }

    /**
     * @param  array<string,mixed>  $env
     * @return array<string,string>
     */
    private function sanitizeEnv(array $env): array
    {
        $out = [];
        foreach ($env as $key => $value) {
            $out[(string) $key] = str_replace(["\n", "\r", "\0"], '', (string) $value);
        }

        return $out;
    }

    private function parseMemoryMb(string $limit): int
    {
        $trim = strtolower(trim($limit));
        if (str_ends_with($trim, 'g')) {
            return (int) (((float) rtrim($trim, 'g')) * 1024);
        }
        if (str_ends_with($trim, 'gb')) {
            return (int) (((float) substr($trim, 0, -2)) * 1024);
        }
        if (str_ends_with($trim, 'm')) {
            return (int) rtrim($trim, 'm');
        }
        if (str_ends_with($trim, 'mb')) {
            return (int) substr($trim, 0, -2);
        }

        return (int) $trim ?: 512;
    }

    /**
     * Collect small workspace files (< 256KB each, total < 4MB) for upload.
     * Larger repos should preseed the Modal image or use a persistent Volume.
     *
     * @return array<int,array{path:string,content_b64:string}>
     */
    private function collectWorkspace(string $worktreePath): array
    {
        if ($worktreePath === '' || ! is_dir($worktreePath)) {
            return [];
        }

        $files = [];
        $totalBytes = 0;
        $maxTotal = 4 * 1024 * 1024;
        $maxPerFile = 256 * 1024;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($worktreePath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (! $file->isFile()) {
                continue;
            }
            $size = $file->getSize();
            if ($size > $maxPerFile || $totalBytes + $size > $maxTotal) {
                continue;
            }
            $relative = ltrim(str_replace($worktreePath, '', $file->getPathname()), DIRECTORY_SEPARATOR);
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                continue;
            }
            $files[] = [
                'path' => $relative,
                'content_b64' => base64_encode($contents),
            ];
            $totalBytes += $size;
        }

        return $files;
    }
}
