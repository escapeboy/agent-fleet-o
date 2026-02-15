<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Experiment\Services\ArtifactContentResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArtifactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'category' => ArtifactContentResolver::category($this->type),
            'experiment_id' => $this->experiment_id,
            'crew_execution_id' => $this->crew_execution_id,
            'project_run_id' => $this->project_run_id,
            'current_version' => $this->current_version,
            'versions_count' => $this->whenCounted('versions'),
            'versions' => $this->whenLoaded('versions', fn () => $this->versions->map(fn ($v) => [
                'id' => $v->id,
                'version' => $v->version,
                'metadata' => $v->metadata,
                'created_at' => $v->created_at->toISOString(),
            ])),
            'preview_url' => route('artifacts.render', $this->id),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
