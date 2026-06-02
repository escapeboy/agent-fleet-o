<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\AgentPolicy;
use App\Domain\Agent\Models\AgentPolicyVersion;
use Illuminate\Support\Facades\DB;

/**
 * Update a policy. Rule changes always create a NEW immutable version and
 * move the current-version pointer — never mutate an existing version, so
 * the pinned version on past proposals stays a faithful record. Name and
 * enabled are mutable in place (they don't affect a recorded decision).
 */
class UpdateAgentPolicyAction
{
    /**
     * @param  array<string, mixed>|null  $rules  null = leave rules unchanged (no new version)
     */
    public function execute(
        AgentPolicy $policy,
        ?array $rules = null,
        ?string $name = null,
        ?bool $enabled = null,
        ?string $createdBy = null,
        ?string $notes = null,
    ): AgentPolicy {
        return DB::transaction(function () use ($policy, $rules, $name, $enabled, $createdBy, $notes): AgentPolicy {
            $attrs = [];
            if ($name !== null) {
                $attrs['name'] = $name;
            }
            if ($enabled !== null) {
                $attrs['enabled'] = $enabled;
            }

            if ($rules !== null) {
                $nextVersion = (int) (AgentPolicyVersion::withoutGlobalScopes()
                    ->where('agent_policy_id', $policy->id)
                    ->max('version') ?? 0) + 1;

                $version = AgentPolicyVersion::withoutGlobalScopes()->create([
                    'team_id' => $policy->team_id,
                    'agent_policy_id' => $policy->id,
                    'version' => $nextVersion,
                    'created_by' => $createdBy,
                    'rules' => $rules,
                    'notes' => $notes,
                    'created_at' => now(),
                ]);

                $attrs['current_version_id'] = $version->id;
            }

            if ($attrs !== []) {
                $policy->update($attrs);
            }

            return $policy->refresh();
        });
    }
}
