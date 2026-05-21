<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Http\Request;

/** @mixin ApprovalRequest */
class ApprovalResource extends FleetQResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'experiment_id' => $this->experiment_id,
            'outbound_proposal_id' => $this->outbound_proposal_id,
            'status' => $this->status->value,
            'reviewed_by' => $this->reviewed_by,
            'rejection_reason' => $this->rejection_reason,
            'reviewer_notes' => $this->reviewer_notes,
            'context' => $this->context,
            'expires_at' => $this->expires_at?->toISOString(),
            'reviewed_at' => $this->reviewed_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
