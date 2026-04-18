<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Signal\Actions\AnalyzeSuspectFilesAction;
use App\Domain\Signal\Actions\ResolveStackTraceAction;
use App\Domain\Signal\Models\Signal;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class EnrichBugReportJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public function __construct(
        public readonly string $signalId,
    ) {
        $this->onQueue('default');
    }

    public function handle(
        ResolveStackTraceAction $resolveStack,
        AnalyzeSuspectFilesAction $analyzeSuspectFiles,
    ): void {
        $signal = Signal::find($this->signalId);

        if (! $signal) {
            return;
        }

        $resolveStack->execute($signal);
        $signal->refresh();

        $analyzeSuspectFiles->execute($signal);
    }
}
