<?php

namespace App\Domain\FeatureFlag\Actions;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\FeatureFlag\Concerns\GatesSensitiveFlagChanges;
use App\Domain\FeatureFlag\DTOs\FeatureFlagResult;
use App\Domain\FeatureFlag\Models\FeatureFlagRollout;
use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Retire a Tier-2 flag: purge all stored per-team overrides and clear its
 * rollout percentage. After archiving, resolution falls back to the static
 * definition default. The definition itself stays in config for history.
 */
class ArchiveFeatureFlagAction
{
    use GatesSensitiveFlagChanges;

    public function __construct(
        private readonly FeatureFlagService $service,
    ) {}

    public function execute(string $key, ?User $actor = null): FeatureFlagResult
    {
        $this->service->definition($key);
        /** @var User|null $actor */
        $actor ??= auth()->user();

        if ($this->requiresApproval($this->service, $key, $actor)) {
            $approval = $this->createFlagApproval('archive', $key, [], $actor?->currentTeam?->id, $actor?->id);

            return FeatureFlagResult::pendingApproval($key, $approval);
        }

        Feature::purge($key);
        FeatureFlagRollout::query()->where('key', $key)->delete();
        $this->service->forgetRolloutCache($key);

        $ocsf = OcsfMapper::classify('feature_flag.archived');
        AuditEntry::create([
            'team_id' => $actor?->currentTeam?->id,
            'user_id' => $actor?->id,
            'event' => 'feature_flag.archived',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => 'feature_flag',
            'subject_id' => $key,
            'properties' => ['flag_key' => $key],
            'created_at' => now(),
        ]);

        return FeatureFlagResult::archived($key);
    }
}
