<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Shared\Models\Team;
use App\Models\GlobalSetting;

/**
 * Gate for the Gap 2 Assistant UI Artifacts feature.
 *
 * Defense in depth:
 *   1. Global kill switch in GlobalSetting — stops the feature for EVERYONE
 *      immediately, no cache, checked on every request. Used by super admins
 *      during an incident.
 *   2. Per-team flag on teams.assistant_ui_artifacts_allowed — default off,
 *      managed from the super-admin dashboard. One row per team.
 *
 * Both must be satisfied for artifacts to be generated or rendered.
 */
class AssistantArtifactsFeatureFlag
{
    private const GLOBAL_KEY = 'assistant.ui_artifacts_enabled';

    public function isEnabledForTeam(?Team $team): bool
    {
        if (! $this->isGloballyEnabled()) {
            return false;
        }

        if ($team === null) {
            return false;
        }

        return (bool) $team->assistant_ui_artifacts_allowed;
    }

    public function isGloballyEnabled(): bool
    {
        $value = GlobalSetting::get(self::GLOBAL_KEY, false);

        return (bool) (is_array($value) ? ($value['enabled'] ?? false) : $value);
    }

    /**
     * Super-admin-only helper: toggle the global kill switch.
     * Keeps callers out of the GlobalSetting key format.
     */
    public function setGlobalEnabled(bool $enabled): void
    {
        GlobalSetting::set(self::GLOBAL_KEY, ['enabled' => $enabled]);
    }
}
