<?php

namespace App\Domain\Approval\DTOs;

use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;

/**
 * A resolved, enabled policy plus the exact version in force. The version id
 * is what gets pinned onto an ActionProposal so the decision is replayable.
 */
class ResolvedPolicy
{
    public function __construct(
        public readonly AgentPolicy $policy,
        public readonly AgentPolicyVersion $version,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->version->rules ?? [];
    }
}
