<?php

namespace App\Domain\Approval\Actions;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Credential\Models\Credential;
use App\Domain\Shared\Models\Team;
use App\Models\GlobalSetting;

class CreateCredentialApprovalRequestAction
{
    public function execute(Credential $credential): ApprovalRequest
    {
        $team = Team::withoutGlobalScopes()->find($credential->team_id);
        $teamTimeout = $team?->settings['approval_timeout_hours'] ?? null;
        $defaultTimeout = $teamTimeout ?? GlobalSetting::get('approval_timeout_hours', 48);

        $creatorName = null;
        if ($credential->creator_id && $credential->creator_type) {
            $creator = $credential->creator;
            $creatorName = $creator?->name ?? $credential->creator_id;
        }

        return ApprovalRequest::withoutGlobalScopes()->create([
            'team_id' => $credential->team_id,
            'credential_id' => $credential->id,
            'status' => ApprovalStatus::Pending,
            'context' => [
                'type' => 'credential_review',
                'credential_id' => $credential->id,
                'credential_name' => $credential->name,
                'credential_type' => $credential->credential_type->value,
                'creator_source' => $credential->creator_source->value,
                'creator_type' => $credential->creator_type,
                'creator_id' => $credential->creator_id,
                'creator_name' => $creatorName,
                'description' => $creatorName
                    ? "Agent '{$creatorName}' created credential '{$credential->name}' during execution. Review before enabling."
                    : "An automated process created credential '{$credential->name}'. Review before enabling.",
            ],
            'expires_at' => now()->addHours((int) $defaultTimeout),
        ]);
    }
}
