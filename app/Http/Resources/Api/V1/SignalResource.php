<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SignalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'experiment_id' => $this->experiment_id,
            'source_type' => $this->source_type,
            'source_identifier' => $this->source_identifier,
            'payload' => $this->payload,
            'score' => $this->score,
            'scoring_details' => $this->scoring_details,
            'tags' => $this->tags,
            'received_at' => $this->received_at?->toISOString(),
            'scored_at' => $this->scored_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
