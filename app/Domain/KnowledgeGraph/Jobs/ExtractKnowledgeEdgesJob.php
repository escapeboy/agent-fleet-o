<?php

namespace App\Domain\KnowledgeGraph\Jobs;

use App\Domain\KnowledgeGraph\Actions\ExtractKnowledgeEdgesAction;
use App\Domain\Signal\Models\Signal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExtractKnowledgeEdgesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 90;

    public int $tries = 2;

    public array $backoff = [30];

    public function __construct(
        public readonly string $signalId,
    ) {
        $this->queue = 'metrics';
    }

    public function handle(ExtractKnowledgeEdgesAction $action): void
    {
        $signal = Signal::withoutGlobalScopes()->find($this->signalId);

        if (! $signal) {
            Log::warning('ExtractKnowledgeEdgesJob: Signal not found', ['signal_id' => $this->signalId]);

            return;
        }

        $action->execute($signal);
    }
}
