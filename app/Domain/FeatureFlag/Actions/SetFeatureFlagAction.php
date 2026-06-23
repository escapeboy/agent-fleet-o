<?php

namespace App\Domain\FeatureFlag\Actions;

use App\Domain\FeatureFlag\Concerns\GatesSensitiveFlagChanges;
use App\Domain\FeatureFlag\DTOs\FeatureFlagResult;
use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Domain\Shared\Models\Team;
use App\Models\User;
use Laravel\Pennant\Feature;

/**
 * Set an explicit per-team override for a Tier-2 flag (activate/deactivate).
 * Pennant's stored value takes precedence over the percentage rollout closure.
 * The applied flip fires Pennant's FeatureUpdated event → RecordFeatureFlagAudit.
 */
class SetFeatureFlagAction
{
    use GatesSensitiveFlagChanges;

    public function __construct(
        private readonly FeatureFlagService $service,
    ) {}

    public function execute(string $key, bool $value, Team $team, ?User $actor = null): FeatureFlagResult
    {
        $this->service->definition($key);
        /** @var User|null $actor */
        $actor ??= auth()->user();

        if ($this->requiresApproval($this->service, $key, $actor)) {
            $approval = $this->createFlagApproval('toggle', $key, [
                'requested_value' => $value,
                'scope_team_id' => $team->id,
            ], $team->id, $actor?->id);

            return FeatureFlagResult::pendingApproval($key, $approval);
        }

        if ($value) {
            Feature::for($team)->activate($key);
        } else {
            Feature::for($team)->deactivate($key);
        }

        return FeatureFlagResult::applied($key, $value, $team);
    }
}
