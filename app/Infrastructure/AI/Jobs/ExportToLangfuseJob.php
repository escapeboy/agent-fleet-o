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
 * Fire-and-forget job that exports a single LLM call to Langfuse as a generation trace.
 * Failure never affects the original AI request — exceptions are swallowed after logging.
 *
 * Langfuse Ingestion API: POST /api/public/ingestion
 * Docs: https://langfuse.com/docs/api
 */
class ExportToLangfuseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        private readonly array $payload,
        private readonly string $host = 'https://cloud.langfuse.com',
        private readonly string $publicKey = '',
        private readonly string $secretKey = '',
    ) {
        $this->onQueue('metrics');
    }

    public function handle(SsrfGuard $ssrfGuard): void
    {
        $host = $this->host;
        $publicKey = $this->publicKey;
        $secretKey = $this->secretKey;

        if (empty($publicKey) || empty($secretKey)) {
            return;
        }

        // Require HTTPS unconditionally — Langfuse receives API keys and prompt data.
        if (parse_url($host, PHP_URL_SCHEME) !== 'https') {
            Log::warning('ExportToLangfuseJob: LANGFUSE_HOST must use https. Export skipped.', ['host' => $host]);

            return;
        }

        // Block private/internal IP ranges when SSRF validation is enabled.
        $ssrfGuard->assertPublicUrl(rtrim($host, '/').'/api/public/ingestion');

        Http::timeout(10)
            ->withBasicAuth($publicKey, $secretKey)
            ->post(rtrim($host, '/').'/api/public/ingestion', [
                'batch' => [$this->payload],
            ]);
    }

    public function failed(\Throwable $e): void
    {
        Log::warning('ExportToLangfuseJob failed', [
            'error' => $e->getMessage(),
            'trace_id' => $this->payload['id'] ?? null,
        ]);
    }
}
