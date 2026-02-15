<?php

namespace App\Console\Commands;

use App\Domain\Approval\Actions\EscalateHumanTaskAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckHumanTaskSla extends Command
{
    protected $signature = 'human-tasks:check-sla';

    protected $description = 'Check SLA deadlines for pending human tasks and escalate if overdue';

    public function handle(EscalateHumanTaskAction $escalate): int
    {
        $overdue = ApprovalRequest::withoutGlobalScopes()
            ->where('status', ApprovalStatus::Pending)
            ->whereNotNull('workflow_node_id')
            ->whereNotNull('sla_deadline')
            ->where('sla_deadline', '<', now())
            ->get();

        $escalated = 0;
        $expired = 0;

        foreach ($overdue as $request) {
            if ($request->hasEscalationLevels()) {
                if ($escalate->execute($request)) {
                    $escalated++;
                }
            } else {
                // No more escalation levels — expire the task
                $request->update([
                    'status' => ApprovalStatus::Expired,
                    'reviewed_at' => now(),
                ]);

                Log::warning('CheckHumanTaskSla: Human task expired after SLA breach', [
                    'approval_id' => $request->id,
                    'experiment_id' => $request->experiment_id,
                    'sla_deadline' => $request->sla_deadline,
                ]);

                $expired++;
            }
        }

        $this->info("SLA check complete: {$escalated} escalated, {$expired} expired.");

        return self::SUCCESS;
    }
}
