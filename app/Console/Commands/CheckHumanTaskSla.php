<?php

namespace App\Console\Commands;

use App\Domain\Approval\Actions\EscalateHumanTaskAction;
use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Experiment\Models\UncertaintySignal;
use App\Domain\Shared\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckHumanTaskSla extends Command
{
    protected $signature = 'human-tasks:check-sla';

    protected $description = 'Check SLA deadlines for pending human tasks and escalate if overdue';

    public function handle(EscalateHumanTaskAction $escalate, NotificationService $notifications): int
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

                if ($request->assigned_to && $request->team_id) {
                    $notifications->notify(
                        userId: $request->assigned_to,
                        teamId: $request->team_id,
                        type: 'human_task.sla_breached',
                        title: 'Human Task SLA Breached',
                        body: 'A task assigned to you has expired without being completed.',
                        actionUrl: '/approvals',
                        data: ['approval_id' => $request->id, 'url' => '/approvals'],
                    );
                }

                $expired++;
            }
        }

        $this->info("SLA check complete: {$escalated} escalated, {$expired} expired.");

        // Escalate expired uncertainty signals
        $expiredSignals = UncertaintySignal::withoutGlobalScopes()
            ->where('status', 'pending')
            ->get()
            ->filter(fn (UncertaintySignal $signal) => $signal->isExpired());

        $escalatedSignals = 0;
        foreach ($expiredSignals as $signal) {
            $signal->update(['status' => 'escalated']);

            Log::info('CheckHumanTaskSla: Uncertainty signal escalated after TTL expiry', [
                'signal_id' => $signal->id,
                'team_id' => $signal->team_id,
                'experiment_stage_id' => $signal->experiment_stage_id,
                'ttl_minutes' => $signal->ttl_minutes,
            ]);

            $escalatedSignals++;
        }

        if ($escalatedSignals > 0) {
            $this->info("Uncertainty signals escalated: {$escalatedSignals}.");
        }

        return self::SUCCESS;
    }
}
