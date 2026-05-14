<?php

namespace App\Infrastructure\AI\Jobs;

use App\Domain\Shared\Services\SsrfGuard;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fire-and-forget OTLP exporter for Phoenix.
 *
 * POSTs an `ExportTraceServiceRequest`-shaped JSON document to
 * `{endpoint}/v1/traces` (Phoenix's OTLP HTTP endpoint). Failure never affects
 * the originating AI request — exceptions are caught and logged.
 *
 * Docker-internal endpoints (e.g. `http://phoenix:6006`) are allowed only when
 * `PHOENIX_ALLOW_HTTP=true`. Public endpoints MUST be HTTPS — anything else
 * gets a warning + skip.
 */
class ExportToPhoenixJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    /**
     * @param  array<string, mixed>  $payload  OTLP ExportTraceServiceRequest JSON shape
     */
    public function __construct(
        private readonly array $payload,
        private readonly string $endpoint,
        private readonly string $apiKey = '',
    ) {
        $this->onQueue('metrics');
    }

    public function handle(SsrfGuard $ssrfGuard): void
    {
        if ($this->endpoint === '') {
            return;
        }

        $url = rtrim($this->endpoint, '/').'/v1/traces';
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME);

        $allowHttp = (bool) config('llmops.phoenix.allow_http', false);

        if ($scheme !== 'https' && ! $allowHttp) {
            Log::warning('ExportToPhoenixJob: non-https endpoint blocked. Set PHOENIX_ALLOW_HTTP=true for docker-internal sidecars.', [
                'endpoint' => $this->endpoint,
            ]);

            return;
        }

        // SSRF guard for public endpoints only — docker-internal sidecars use
        // RFC1918 / docker-bridge IPs that the guard would otherwise block.
        if ($scheme === 'https') {
            $ssrfGuard->assertPublicUrl($url);
        }

        $request = Http::timeout(5)->asJson();

        if ($this->apiKey !== '') {
            $request = $request->withHeaders(['Authorization' => 'Bearer '.$this->apiKey]);
        }

        $request->post($url, $this->payload);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('ExportToPhoenixJob: export failed', [
            'error' => $e->getMessage(),
            'endpoint' => $this->endpoint,
        ]);
    }
}
