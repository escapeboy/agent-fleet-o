<?php

namespace App\Console\Commands;

use App\Domain\Approval\Actions\ApproveAction;
use App\Domain\Approval\Enums\ApprovalMode;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoApproveOnLoopApprovalsCommand extends Command
{
    protected $signature = 'approvals:auto-approve-on-loop';

    protected $description = 'Auto-approve on-loop approval requests whose intervention window has expired.';

    public function handle(ApproveAction $approveAction): int
    {
        $expired = ApprovalRequest::withoutGlobalScopes()
            ->where('mode', ApprovalMode::OnLoop)
            ->where('status', ApprovalStatus::Pending)
            ->whereNull('auto_approved_at')
            ->whereRaw('created_at + make_interval(secs => intervention_window_seconds) <= NOW()')
            ->get();

        if ($expired->isEmpty()) {
            return self::SUCCESS;
        }

        foreach ($expired as $request) {
            try {
                $approveAction->execute(
                    approvalRequest: $request,
                    reviewerId: null,
                    notes: 'Auto-approved: intervention window expired (on-loop mode).',
                );

                $request->update(['auto_approved_at' => now()]);

                Log::info('AutoApproveOnLoop: auto-approved', [
                    'approval_request_id' => $request->id,
                    'experiment_id' => $request->experiment_id,
                    'window_seconds' => $request->intervention_window_seconds,
                ]);
            } catch (\Throwable $e) {
                Log::error('AutoApproveOnLoop: failed to auto-approve', [
                    'approval_request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Auto-approved {$expired->count()} on-loop approval(s).");

        return self::SUCCESS;
    }
}
