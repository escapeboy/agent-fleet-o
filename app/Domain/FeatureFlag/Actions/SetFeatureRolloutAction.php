<?php

namespace App\Domain\FeatureFlag\Actions;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Audit\Services\OcsfMapper;
use App\Domain\FeatureFlag\Concerns\GatesSensitiveFlagChanges;
use App\Domain\FeatureFlag\DTOs\FeatureFlagResult;
use App\Domain\FeatureFlag\Models\FeatureFlagRollout;
use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Models\User;

/**
 * Set the platform-wide percentage rollout for a Tier-2 flag (0..100).
 * Stored in feature_flag_rollouts (our table, not Pennant), so the change is
 * audited here rather than via Pennant's FeatureUpdated event.
 */
class SetFeatureRolloutAction
{
    use GatesSensitiveFlagChanges;

    public function __construct(
        private readonly FeatureFlagService $service,
    ) {}

    public function execute(string $key, int $percentage, ?User $actor = null): FeatureFlagResult
    {
        $this->service->definition($key);
        /** @var User|null $actor */
        $actor ??= auth()->user();
        $clamped = max(0, min(100, $percentage));

        if ($this->requiresApproval($this->service, $key, $actor)) {
            $approval = $this->createFlagApproval('rollout', $key, [
                'requested_percentage' => $clamped,
            ], $actor?->currentTeam?->id, $actor?->id);

            return FeatureFlagResult::pendingApproval($key, $approval);
        }

        FeatureFlagRollout::query()->updateOrCreate(
            ['key' => $key],
            ['percentage' => $clamped, 'updated_by' => $actor?->id],
        );

        $this->service->forgetRolloutCache($key);

        $ocsf = OcsfMapper::classify('feature_flag.rollout');
        AuditEntry::create([
            'team_id' => $actor?->currentTeam?->id,
            'user_id' => $actor?->id,
            'event' => 'feature_flag.rollout',
            'ocsf_class_uid' => $ocsf['class_uid'],
            'ocsf_severity_id' => $ocsf['severity_id'],
            'subject_type' => 'feature_flag',
            'subject_id' => $key,
            'properties' => [
                'flag_key' => $key,
                'percentage' => $clamped,
            ],
            'created_at' => now(),
        ]);

        return FeatureFlagResult::rollout($key, $clamped);
    }
}
