<?php

namespace App\Domain\Budget\Actions;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Shared\Services\NotificationService;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Log;

class AlertOnLowBudget
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * Check experiment budget utilization and create an alert if above threshold.
     */
    public function execute(Experiment $experiment): bool
    {
        if ($experiment->budget_cap_credits <= 0) {
            return false;
        }

        $threshold = GlobalSetting::get('budget_alert_threshold_pct', 80);
        $pctUsed = ($experiment->budget_spent_credits / $experiment->budget_cap_credits) * 100;

        if ($pctUsed < $threshold) {
            return false;
        }

        // Avoid duplicate alerts — check if one was created in the last hour
        $recentAlert = AuditEntry::where('event', 'budget.low_warning')
            ->where('subject_type', Experiment::class)
            ->where('subject_id', $experiment->id)
            ->where('created_at', '>=', now()->subHour())
            ->exists();

        if ($recentAlert) {
            return false;
        }

        $ocsf = OcsfMapper::classify('budget.low_warning');
        AuditEntry::create([
            'user_id' => $experiment->user_id,
            'event' => 'budget.low_warning',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => Experiment::class,
            'subject_id' => $experiment->id,
            'properties' => [
                'pct_used' => round($pctUsed, 1),
                'spent' => $experiment->budget_spent_credits,
                'cap' => $experiment->budget_cap_credits,
                'threshold' => $threshold,
            ],
            'created_at' => now(),
        ]);

        Log::info('AlertOnLowBudget: Budget warning', [
            'experiment_id' => $experiment->id,
            'pct_used' => round($pctUsed, 1),
        ]);

        if ($experiment->team_id && $experiment->user_id) {
            $this->notifications->notify(
                userId: $experiment->user_id,
                teamId: $experiment->team_id,
                type: 'experiment.budget.warning',
                title: 'Experiment Budget Warning',
                body: sprintf(
                    'Budget %s%% used on "%s". Consider increasing the cap.',
                    round($pctUsed, 0),
                    $experiment->name ?? 'Experiment',
                ),
                actionUrl: '/experiments/'.$experiment->id,
                data: ['experiment_id' => $experiment->id, 'url' => '/experiments/'.$experiment->id],
            );
        }

        return true;
    }
}
