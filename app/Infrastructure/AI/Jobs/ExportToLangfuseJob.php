<?php

namespace App\Infrastructure\AI\Jobs;

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
    ) {
        $this->onQueue('metrics');
    }

    public function handle(): void
    {
        $host = config('llmops.langfuse.host', 'https://cloud.langfuse.com');
        $publicKey = config('llmops.langfuse.public_key', '');
        $secretKey = config('llmops.langfuse.secret_key', '');

        if (empty($publicKey) || empty($secretKey)) {
            return;
        }

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
