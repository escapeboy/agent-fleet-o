<?php

namespace App\Domain\Tool\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Models\ToolFederationGroup;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class ToolFederationResolver
{
    /**
     * Resolve the federated tool pool for an agent.
     * Returns an empty collection when federation is disabled.
     *
     * @return Collection<int, Tool>
     */
    public function resolve(Agent $agent): Collection
    {
        if (! ($agent->config['use_tool_federation'] ?? false)) {
            return collect();
        }

        try {
            $groupId = $agent->config['tool_federation_group_id'] ?? null;

            $query = Tool::query()
                ->where('team_id', $agent->team_id)
                ->where('status', ToolStatus::Active);

            if ($groupId) {
                $group = ToolFederationGroup::where('team_id', $agent->team_id)
                    ->where('id', $groupId)
                    ->where('is_active', true)
                    ->first();

                if ($group && ! empty($group->tool_ids)) {
                    $query->whereIn('id', $group->tool_ids);
                }
            }

            return $query->get();
        } catch (\Throwable $e) {
            Log::warning('ToolFederationResolver: failed to resolve tool pool, falling back to explicit tools', [
                'agent_id' => $agent->id,
                'error' => $e->getMessage(),
            ]);

            return collect();
        }
    }
}
