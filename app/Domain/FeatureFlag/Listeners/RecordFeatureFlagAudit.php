<?php

namespace App\Domain\FeatureFlag\Listeners;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\Shared\Models\Team;
use Laravel\Pennant\Events\FeatureUpdated;

/**
 * Audits per-team override flips applied through Pennant (SetFeatureFlagAction).
 * Rollout-percentage and archive changes are audited inside their own actions
 * because they do not flow through Pennant's FeatureUpdated event.
 */
class RecordFeatureFlagAudit
{
    public function handle(FeatureUpdated $event): void
    {
        $definitions = config('feature_flags.definitions', []);

        if (! array_key_exists($event->feature, $definitions)) {
            return;
        }

        $teamId = $event->scope instanceof Team ? $event->scope->getKey() : null;
        $ocsf = OcsfMapper::classify('feature_flag.updated');

        AuditEntry::create([
            'team_id' => $teamId,
            'user_id' => auth()->id(),
            'event' => 'feature_flag.updated',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => 'feature_flag',
            'subject_id' => $event->feature,
            'properties' => [
                'flag_key' => $event->feature,
                'value' => $event->value,
                'scope_team_id' => $teamId,
            ],
            'created_at' => now(),
        ]);
    }
}
