<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Services\SignalRelevanceScorer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScoreLearnedSignalRelevanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly string $signalId,
    ) {
        $this->onQueue('metrics');
    }

    public function handle(SignalRelevanceScorer $scorer): void
    {
        $signal = Signal::withoutGlobalScopes()->find($this->signalId);

        if (! $signal) {
            return;
        }

        try {
            $scorer->score($signal);
        } catch (\Throwable $e) {
            Log::warning('ScoreLearnedSignalRelevanceJob: failed to score signal', [
                'signal_id' => $this->signalId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
