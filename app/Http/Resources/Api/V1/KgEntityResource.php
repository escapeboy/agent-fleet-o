<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KgEntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'canonical_name' => $this->canonical_name,
            'mention_count' => $this->mention_count,
            'metadata' => $this->metadata,
            'first_seen_at' => $this->first_seen_at?->toISOString(),
            'last_seen_at' => $this->last_seen_at?->toISOString(),
        ];
    }
}
