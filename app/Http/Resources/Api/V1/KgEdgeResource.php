<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KgEdgeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'relation_type' => $this->relation_type,
            'fact' => $this->fact,
            'source_entity' => $this->whenLoaded('sourceEntity', fn () => [
                'id' => $this->sourceEntity->id,
                'name' => $this->sourceEntity->name,
                'type' => $this->sourceEntity->type,
            ]),
            'target_entity' => $this->whenLoaded('targetEntity', fn () => [
                'id' => $this->targetEntity->id,
                'name' => $this->targetEntity->name,
                'type' => $this->targetEntity->type,
            ]),
            'attributes' => $this->attributes,
            'episode_id' => $this->episode_id,
            'valid_at' => $this->valid_at?->toISOString(),
            'invalid_at' => $this->invalid_at?->toISOString(),
            'similarity' => isset($this->similarity) ? round((float) $this->similarity, 4) : null,
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
