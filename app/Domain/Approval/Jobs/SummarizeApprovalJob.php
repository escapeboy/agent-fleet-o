<?php

namespace App\Domain\Approval\Jobs;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Approval\Services\ApprovalSummarizer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Best-effort LLM enrichment of an approval card. Runs off the request path so
 * a slow or failing gateway never delays approval availability. On failure it
 * records the error into context and returns — it must never bubble.
 */
class SummarizeApprovalJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly string $approvalRequestId,
    ) {
        $this->onQueue('ai-calls');
    }

    public function handle(ApprovalSummarizer $summarizer): void
    {
        $approval = ApprovalRequest::withoutGlobalScopes()->find($this->approvalRequestId);

        if (! $approval) {
            return;
        }

        try {
            $result = $summarizer->summarize($approval);

            $context = $approval->context ?? [];
            $context['ai_summary'] = $result;
            $approval->update(['context' => $context]);
        } catch (Throwable $e) {
            Log::warning('SummarizeApprovalJob failed', [
                'approval_id' => $this->approvalRequestId,
                'error' => $e->getMessage(),
            ]);

            $context = $approval->context ?? [];
            $context['ai_summary_error'] = $e->getMessage();
            $approval->update(['context' => $context]);
        }
    }
}
