<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Agent\Models\AgentPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AgentPolicy
 */
class AgentPolicyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'agent_id' => $this->agent_id,
            'scope' => $this->agent_id === null ? 'team_default' : 'agent',
            'status' => $this->status->value,
            'enabled' => $this->enabled,
            'current_version_id' => $this->current_version_id,
            'current_version' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion?->version),
            'rules' => $this->whenLoaded('currentVersion', fn () => $this->currentVersion?->rules),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
