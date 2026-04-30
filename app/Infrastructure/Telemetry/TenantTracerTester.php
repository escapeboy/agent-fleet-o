<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;

/**
 * Probes a team's configured OTLP endpoint to confirm connectivity + auth.
 *
 * Sends a minimal empty-body POST to `<endpoint>/v1/traces` with the
 * configured Authorization header. Interprets the HTTP response:
 *   - 200/202        → "ok" (collector accepted)
 *   - 400/415/422    → "ok_auth_valid" (payload rejected but auth + endpoint work)
 *   - 401/403        → "auth_failed"
 *   - 404            → "endpoint_not_found"
 *   - 5xx            → "collector_error"
 *   - network error  → "unreachable"
 *
 * Does NOT persist. Callers use this for pre-save validation in the UI /
 * post-save sanity check from MCP.
 */
final class TenantTracerTester
{
    public function __construct(
        private readonly SsrfGuard $ssrfGuard,
    ) {}

    /**
     * @param  array<string, mixed>  $observabilitySettings  shape from Team.settings.observability
     * @return array{
     *   ok: bool,
     *   status: string,
     *   http_status: ?int,
     *   latency_ms: ?int,
     *   message: string
     * }
     */
    public function test(array $observabilitySettings): array
    {
        $endpoint = trim((string) ($observabilitySettings['endpoint'] ?? ''));
        if ($endpoint === '') {
            return $this->fail('not_configured', null, 'No endpoint configured.');
        }

        if (! filter_var($endpoint, FILTER_VALIDATE_URL)) {
            return $this->fail('invalid_endpoint', null, 'Endpoint is not a valid URL.');
        }

        $scheme = parse_url($endpoint, PHP_URL_SCHEME);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return $this->fail('invalid_scheme', null, 'Endpoint scheme must be http or https.');
        }

        try {
            $this->ssrfGuard->assertPublicUrl($endpoint);
        } catch (\InvalidArgumentException $e) {
            return $this->fail('ssrf_blocked', null, $e->getMessage());
        }

        $probeUrl = rtrim($endpoint, '/').'/v1/traces';
        $headers = ['Content-Type' => 'application/x-protobuf'];

        $tokenEncrypted = (string) ($observabilitySettings['otlp_token_encrypted'] ?? '');
        if ($tokenEncrypted !== '') {
            try {
                $token = Crypt::decryptString($tokenEncrypted);
                if ($token !== '') {
                    $headers['Authorization'] = $this->normaliseAuth($token);
                }
            } catch (DecryptException) {
                return $this->fail('token_corrupt', null, 'Stored token ciphertext is malformed. Paste the token again.');
            }
        }

        $started = hrtime(true);
        try {
            // Empty-body POST: a valid ExportTraceServiceRequest protobuf with zero
            // resource_spans. Most collectors accept as success, some 400. Either
            // proves auth + network. Keep body literally empty for minimal payload.
            $response = Http::timeout(5)
                ->connectTimeout(3)
                ->withHeaders($headers)
                ->withBody('', 'application/x-protobuf')
                ->post($probeUrl);
            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
            $status = $response->status();

            return $this->classify($status, $latencyMs);
        } catch (ConnectionException $e) {
            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

            return $this->fail('unreachable', null, 'Could not reach the endpoint ('.$latencyMs.' ms): '.$this->shortError($e), latencyMs: $latencyMs);
        } catch (\Throwable $e) {
            $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);

            return $this->fail('unexpected_error', null, 'Unexpected error: '.$this->shortError($e), latencyMs: $latencyMs);
        }
    }

    private function classify(int $status, int $latencyMs): array
    {
        if ($status === 200 || $status === 202) {
            return [
                'ok' => true,
                'status' => 'ok',
                'http_status' => $status,
                'latency_ms' => $latencyMs,
                'message' => sprintf('Collector accepted (HTTP %d, %d ms).', $status, $latencyMs),
            ];
        }
        if (in_array($status, [400, 415, 422], true)) {
            return [
                'ok' => true,
                'status' => 'ok_auth_valid',
                'http_status' => $status,
                'latency_ms' => $latencyMs,
                'message' => sprintf('Auth + endpoint work (HTTP %d on empty probe is expected, %d ms).', $status, $latencyMs),
            ];
        }
        if ($status === 401 || $status === 403) {
            return $this->fail('auth_failed', $status, sprintf('Auth rejected (HTTP %d). Check the token.', $status), latencyMs: $latencyMs);
        }
        if ($status === 404) {
            return $this->fail('endpoint_not_found', $status, 'Endpoint returned 404. Check the URL — FleetQ appends /v1/traces automatically.', latencyMs: $latencyMs);
        }
        if ($status >= 500) {
            return $this->fail('collector_error', $status, sprintf('Collector returned HTTP %d. Transient? Retry in a minute.', $status), latencyMs: $latencyMs);
        }

        return $this->fail('unexpected_status', $status, sprintf('Unexpected HTTP %d response.', $status), latencyMs: $latencyMs);
    }

    private function fail(string $status, ?int $httpStatus, string $message, ?int $latencyMs = null): array
    {
        return [
            'ok' => false,
            'status' => $status,
            'http_status' => $httpStatus,
            'latency_ms' => $latencyMs,
            'message' => $message,
        ];
    }

    private function normaliseAuth(string $token): string
    {
        $trim = trim($token);
        if (str_starts_with(strtolower($trim), 'bearer ') || str_starts_with(strtolower($trim), 'basic ')) {
            return $trim;
        }

        return 'Bearer '.$trim;
    }

    private function shortError(\Throwable $e): string
    {
        return mb_strimwidth($e->getMessage(), 0, 160, '…');
    }
}
