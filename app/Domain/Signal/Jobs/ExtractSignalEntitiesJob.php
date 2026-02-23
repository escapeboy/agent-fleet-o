<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Signal\Actions\ExtractEntitiesAction;
use App\Domain\Signal\Models\Signal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractSignalEntitiesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(
        public readonly string $signalId,
    ) {
        $this->queue = 'metrics';
    }

    public function handle(ExtractEntitiesAction $action): void
    {
        $signal = Signal::withoutGlobalScopes()->find($this->signalId);

        if (! $signal) {
            Log::warning('ExtractSignalEntitiesJob: Signal not found', ['signal_id' => $this->signalId]);

            return;
        }

        // Skip if signal has very little content
        $payload = $signal->payload ?? [];
        $text = ($payload['title'] ?? $payload['subject'] ?? '').' '.($payload['description'] ?? $payload['body'] ?? $payload['content'] ?? '');
        if (mb_strlen(trim($text)) < 20) {
            return;
        }

        $action->execute($signal);
    }
}
