<?php

namespace App\Domain\Signal\Connectors;

use App\Domain\Signal\Actions\IngestSignalAction;
use App\Domain\Signal\Contracts\InputConnectorInterface;
use App\Domain\Signal\Models\Signal;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * HTTP/URL health monitor connector.
 *
 * Monitors a URL for availability changes, content changes, and SSL expiry.
 * Uses a HEAD-first strategy with conditional GET to minimise bandwidth.
 *
 * Config keys:
 *   url                        — URL to monitor (required)
 *   monitor_type               — 'availability' | 'content_change' | 'both' (default: 'availability')
 *   expected_status            — array of acceptable status codes (default: [200])
 *   response_time_threshold_ms — alert if response exceeds this in ms (default: null = disabled)
 *   timeout                    — HTTP timeout in seconds (default: 15)
 *   follow_redirects           — follow redirects (default: true)
 *   verify_ssl                 — verify SSL certificate (default: true)
 *   ssl_check                  — check SSL expiry (default: true)
 *   ssl_expiry_days_threshold  — alert N days before expiry (default: 14)
 *   headers                    — custom request headers array
 *   last_content_hash          — SHA-256 hash of last body (state)
 *   last_etag                  — last ETag value (state)
 *   last_modified              — last Last-Modified header (state)
 *   last_status                — last HTTP status code (state)
 *   consecutive_failures       — failure count for backoff (state)
 */
class HttpMonitorConnector implements InputConnectorInterface
{
    public function __construct(
        private readonly IngestSignalAction $ingestAction,
    ) {}

    /** Stores updated state between poll() and getUpdatedConfig(). */
    private array $updatedState = [];

    /**
     * @return Signal[]
     */
    public function poll(array $config): array
    {
        $url = $config['url'] ?? null;

        if (! $url) {
            Log::warning('HttpMonitorConnector: No URL configured');

            return [];
        }

        $monitorType = $config['monitor_type'] ?? 'availability';
        $expectedStatuses = $config['expected_status'] ?? [200];
        $timeout = (int) ($config['timeout'] ?? 15);
        $followRedirects = $config['follow_redirects'] ?? true;
        $verifySsl = $config['verify_ssl'] ?? true;
        $headers = $config['headers'] ?? [];
        $responseMsThreshold = $config['response_time_threshold_ms'] ?? null;
        $lastStatus = $config['last_status'] ?? null;
        $lastEtag = $config['last_etag'] ?? null;
        $lastModified = $config['last_modified'] ?? null;
        $lastHash = $config['last_content_hash'] ?? null;

        $this->updatedState = [
            'last_status' => $lastStatus,
            'last_etag' => $lastEtag,
            'last_modified' => $lastModified,
            'last_content_hash' => $lastHash,
            'consecutive_failures' => (int) ($config['consecutive_failures'] ?? 0),
        ];

        $startTime = microtime(true);

        try {
            $requestHeaders = array_merge(
                ['User-Agent' => 'AgentFleet-Monitor/1.0'],
                $headers,
            );

            // Layer 1: HEAD request to check status/ETag cheaply
            $headResponse = Http::timeout($timeout)
                ->withOptions([
                    'verify' => $verifySsl,
                    'allow_redirects' => $followRedirects ? ['max' => 5] : false,
                    'connect_timeout' => 5,
                ])
                ->withHeaders($requestHeaders)
                ->head($url);

            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);
            $statusCode = $headResponse->status();
            $etag = $headResponse->header('ETag') ?: null;
            $lastModifiedHeader = $headResponse->header('Last-Modified') ?: null;

            $this->updatedState['last_status'] = $statusCode;
            $this->updatedState['consecutive_failures'] = 0;

            if ($etag) {
                $this->updatedState['last_etag'] = $etag;
            }

            if ($lastModifiedHeader) {
                $this->updatedState['last_modified'] = $lastModifiedHeader;
            }

            $signals = [];

            // Check: status code change
            $isStatusOk = in_array($statusCode, $expectedStatuses, true);
            $wasStatusOk = $lastStatus === null || in_array($lastStatus, $expectedStatuses, true);

            if ($statusCode !== $lastStatus && $lastStatus !== null) {
                if (! $isStatusOk || ! $wasStatusOk) {
                    $signal = $this->createSignal($url, 'status_change', [
                        'previous_status' => $lastStatus,
                        'current_status' => $statusCode,
                        'is_healthy' => $isStatusOk,
                    ], $config);

                    if ($signal) {
                        $signals[] = $signal;
                    }
                }
            }

            // Check: slow response
            if ($responseMsThreshold && $responseTimeMs > $responseMsThreshold) {
                $signal = $this->createSignal($url, 'slow_response', [
                    'response_time_ms' => $responseTimeMs,
                    'threshold_ms' => $responseMsThreshold,
                    'status_code' => $statusCode,
                ], $config);

                if ($signal) {
                    $signals[] = $signal;
                }
            }

            // Layer 2/3: Content change check (skip on non-200 responses or if availability-only)
            if (in_array($monitorType, ['content_change', 'both'], true) && $isStatusOk) {
                $contentSignal = $this->checkContentChange(
                    $url, $config, $requestHeaders, $timeout, $verifySsl, $followRedirects,
                    $etag, $lastEtag, $lastModifiedHeader, $lastModified, $lastHash,
                );

                if ($contentSignal) {
                    $signals[] = $contentSignal;
                }
            }

            // Check SSL expiry (on any successful response)
            if (($config['ssl_check'] ?? true) && str_starts_with($url, 'https://') && $isStatusOk) {
                $sslSignal = $this->checkSslExpiry($url, $config);

                if ($sslSignal) {
                    $signals[] = $sslSignal;
                }
            }

            return $signals;
        } catch (\Throwable $e) {
            $this->updatedState['consecutive_failures'] = (int) ($config['consecutive_failures'] ?? 0) + 1;

            Log::warning('HttpMonitorConnector: Request failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);

            // Create unavailability signal if we had a healthy status before
            if ($lastStatus !== null && in_array($lastStatus, $expectedStatuses, true)) {
                $signal = $this->createSignal($url, 'unavailable', [
                    'error' => $e->getMessage(),
                    'previous_status' => $lastStatus,
                ], $config);

                return $signal ? [$signal] : [];
            }

            return [];
        }
    }

    public function supports(string $driver): bool
    {
        return $driver === 'http_monitor';
    }

    public function getUpdatedConfig(array $config, array $signals): array
    {
        foreach ($this->updatedState as $key => $value) {
            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        return $config;
    }

    private function checkContentChange(
        string $url,
        array $config,
        array $requestHeaders,
        int $timeout,
        bool $verifySsl,
        bool $followRedirects,
        ?string $currentEtag,
        ?string $lastEtag,
        ?string $currentLastModified,
        ?string $lastModified,
        ?string $lastHash,
    ): ?Signal {
        // Use conditional GET if we have ETag or Last-Modified
        $conditionalHeaders = [];

        if ($currentEtag && $lastEtag && $currentEtag === $lastEtag) {
            return null; // ETag unchanged — no content change
        }

        if ($lastEtag) {
            $conditionalHeaders['If-None-Match'] = $lastEtag;
        } elseif ($lastModified) {
            $conditionalHeaders['If-Modified-Since'] = $lastModified;
        }

        $response = Http::timeout($timeout)
            ->withOptions([
                'verify' => $verifySsl,
                'allow_redirects' => $followRedirects ? ['max' => 5] : false,
            ])
            ->withHeaders(array_merge($requestHeaders, $conditionalHeaders))
            ->get($url);

        // 304 Not Modified — no change
        if ($response->status() === 304) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $body = $response->body();
        $newHash = hash('sha256', $body);

        if ($newHash === $lastHash) {
            return null; // Content identical
        }

        $this->updatedState['last_content_hash'] = $newHash;

        if ($response->header('ETag')) {
            $this->updatedState['last_etag'] = $response->header('ETag');
        }

        if ($response->header('Last-Modified')) {
            $this->updatedState['last_modified'] = $response->header('Last-Modified');
        }

        return $this->createSignal($url, 'content_change', [
            'previous_hash' => $lastHash,
            'new_hash' => $newHash,
            'content_length' => strlen($body),
        ], $config);
    }

    private function checkSslExpiry(string $url, array $config): ?Signal
    {
        $thresholdDays = (int) ($config['ssl_expiry_days_threshold'] ?? 14);
        $host = parse_url($url, PHP_URL_HOST);

        if (! $host) {
            return null;
        }

        try {
            $streamContext = stream_context_create([
                'ssl' => ['capture_peer_cert' => true],
            ]);

            $client = @stream_socket_client(
                "ssl://{$host}:443",
                $errno,
                $errstr,
                10,
                STREAM_CLIENT_CONNECT,
                $streamContext,
            );

            if (! $client) {
                return null;
            }

            $params = stream_context_get_params($client);
            fclose($client);

            $cert = $params['options']['ssl']['peer_certificate'] ?? null;

            if (! $cert) {
                return null;
            }

            $certInfo = openssl_x509_parse($cert);
            $expiresAt = $certInfo['validTo_time_t'] ?? null;

            if (! $expiresAt) {
                return null;
            }

            $daysUntilExpiry = (int) (($expiresAt - time()) / 86400);

            if ($daysUntilExpiry > $thresholdDays) {
                return null; // Still healthy
            }

            return $this->createSignal($url, 'ssl_expiry', [
                'days_until_expiry' => $daysUntilExpiry,
                'expires_at' => date('Y-m-d', $expiresAt),
                'host' => $host,
                'issuer' => $certInfo['issuer']['O'] ?? null,
            ], $config);
        } catch (\Throwable) {
            return null;
        }
    }

    private function createSignal(string $url, string $eventType, array $eventData, array $config): ?Signal
    {
        $defaultTags = $config['default_tags'] ?? [];
        $host = parse_url($url, PHP_URL_HOST) ?? $url;

        return $this->ingestAction->execute(
            sourceType: 'http_monitor',
            sourceIdentifier: $url,
            sourceNativeId: "http_monitor.{$eventType}.{$host}.".time(),
            payload: array_merge([
                'url' => $url,
                'host' => $host,
                'event_type' => $eventType,
            ], $eventData),
            tags: array_values(array_unique(
                array_merge(['http_monitor', $eventType], $defaultTags),
            )),
        );
    }
}
