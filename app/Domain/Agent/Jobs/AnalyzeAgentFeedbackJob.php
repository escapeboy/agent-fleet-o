<?php

namespace App\Domain\Agent\Jobs;

use App\Domain\Agent\Actions\AnalyzeAgentFeedbackAction;
use App\Domain\Agent\Models\Agent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class AnalyzeAgentFeedbackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public readonly Agent $agent,
    ) {
        $this->onQueue('default');
    }

    public function handle(AnalyzeAgentFeedbackAction $action): void
    {
        $action->execute($this->agent);
    }
}
