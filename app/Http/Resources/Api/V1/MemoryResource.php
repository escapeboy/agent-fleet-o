<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'agent_id' => $this->agent_id,
            'project_id' => $this->project_id,
            'content' => $this->content,
            'source_type' => $this->source_type,
            'source_id' => $this->source_id,
            'metadata' => $this->metadata,
            'tags' => $this->tags,
            'confidence' => $this->confidence,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
