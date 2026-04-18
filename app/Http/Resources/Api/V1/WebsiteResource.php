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
            'settings' => $this->settings,
            'custom_domain' => $this->custom_domain,
            'user_id' => $this->user_id,
            'page_count' => $this->whenCounted('pages'),
            'pages' => $this->whenLoaded('pages', fn () => $this->pages->map(fn ($page) => [
                'id' => $page->id,
                'slug' => $page->slug,
                'title' => $page->title,
                'page_type' => $page->page_type->value,
                'status' => $page->status->value,
                'sort_order' => $page->sort_order,
                'published_at' => $page->published_at?->toISOString(),
            ])->values()),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
