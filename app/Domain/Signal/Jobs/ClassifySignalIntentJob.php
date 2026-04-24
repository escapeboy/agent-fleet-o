<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Signal\Actions\ClassifySignalIntentAction;
use App\Domain\Signal\Models\Signal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ClassifySignalIntentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $signalId) {}

    public function handle(ClassifySignalIntentAction $action): void
    {
        $signal = Signal::withoutGlobalScopes()->find($this->signalId);
        if ($signal === null) {
            return;
        }

        // Skip if already classified this turn to avoid double-spend.
        $meta = $signal->metadata ?? [];
        if (isset($meta['inferred_intent'])) {
            return;
        }

        $action->execute($signal);
    }
}
