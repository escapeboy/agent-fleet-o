<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsiteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'status' => $this->status->value,
            'custom_domain' => $this->custom_domain,
            'settings' => $this->settings,
            'page_count' => $this->whenCounted('pages', fn () => $this->pages_count),
            'pages' => $this->whenLoaded('pages', fn () => WebsitePageResource::collection($this->pages)),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
