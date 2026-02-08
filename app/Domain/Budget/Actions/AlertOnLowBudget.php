<?php

namespace App\Domain\Budget\Actions;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Experiment\Models\Experiment;
use App\Models\GlobalSetting;
use Illuminate\Support\Facades\Log;

class AlertOnLowBudget
{
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

        AuditEntry::create([
            'user_id' => $experiment->user_id,
            'event' => 'budget.low_warning',
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

        return true;
    }
}
