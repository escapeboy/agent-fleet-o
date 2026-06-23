<?php

namespace App\Domain\FeatureFlag\DTOs;

use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\Shared\Models\Team;

/**
 * Outcome of a feature-flag mutation. Either the change was applied immediately
 * (non-sensitive, or actor is super-admin) or it is pending an ApprovalRequest
 * (sensitive flag flipped by a non-super-admin actor — e.g. an agent).
 */
final class FeatureFlagResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?string $key = null,
        public readonly ?bool $value = null,
        public readonly ?int $percentage = null,
        public readonly ?string $teamId = null,
        public readonly ?string $approvalId = null,
    ) {}

    public static function applied(string $key, bool $value, ?Team $team): self
    {
        return new self('applied', key: $key, value: $value, teamId: $team?->id);
    }

    public static function rollout(string $key, int $percentage): self
    {
        return new self('rollout', key: $key, percentage: $percentage);
    }

    public static function archived(string $key): self
    {
        return new self('archived', key: $key);
    }

    public static function pendingApproval(string $key, ApprovalRequest $approval): self
    {
        return new self('pending_approval', key: $key, approvalId: $approval->id);
    }

    public function isPendingApproval(): bool
    {
        return $this->status === 'pending_approval';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'status' => $this->status,
            'flag_key' => $this->key,
            'value' => $this->value,
            'percentage' => $this->percentage,
            'scope_team_id' => $this->teamId,
            'approval_id' => $this->approvalId,
        ], fn ($v) => $v !== null);
    }
}
