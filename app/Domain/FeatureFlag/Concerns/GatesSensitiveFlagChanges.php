<?php

namespace App\Domain\FeatureFlag\Concerns;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\FeatureFlag\Services\FeatureFlagService;
use App\Models\User;

/**
 * Sensitivity gate shared by the feature-flag mutation actions.
 *
 * A sensitive flag (security/billing/destructive) flipped by a non-super-admin
 * actor — typically an agent acting as team owner over MCP — must not apply
 * directly; it creates a pending ApprovalRequest instead (mirrors
 * CreateSecurityReviewRequestAction's context-discriminated approval).
 */
trait GatesSensitiveFlagChanges
{
    protected function requiresApproval(FeatureFlagService $service, string $key, ?User $actor): bool
    {
        if (! $service->isSensitive($key)) {
            return false;
        }

        return $actor === null || ! $actor->is_super_admin;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function createFlagApproval(string $changeType, string $key, array $context, ?string $teamId, ?string $actorId): ApprovalRequest
    {
        return ApprovalRequest::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'status' => ApprovalStatus::Pending,
            'context' => array_merge([
                'type' => 'feature_flag_change',
                'change_type' => $changeType,
                'flag_key' => $key,
                'requested_by' => $actorId,
            ], $context),
            'expires_at' => now()->addDays(3),
        ]);
    }
}
