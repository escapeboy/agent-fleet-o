<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EvolutionProposalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'               => $this->id,
            'agent_id'         => $this->agent_id,
            'execution_id'     => $this->execution_id,
            'status'           => $this->status->value,
            'analysis'         => $this->analysis,
            'proposed_changes' => $this->proposed_changes,
            'reasoning'        => $this->reasoning,
            'confidence_score' => $this->confidence_score,
            'reviewed_by'      => $this->reviewed_by,
            'reviewed_at'      => $this->reviewed_at?->toISOString(),
            'created_at'       => $this->created_at->toISOString(),
            'updated_at'       => $this->updated_at->toISOString(),
        ];
    }
}
