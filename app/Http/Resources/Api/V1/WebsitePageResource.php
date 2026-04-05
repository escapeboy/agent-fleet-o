<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class WebsitePageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'website_id' => $this->website_id,
            'slug' => $this->slug,
            'title' => $this->title,
            'page_type' => $this->page_type->value,
            'status' => $this->status->value,
            'meta' => $this->meta,
            'sort_order' => $this->sort_order,
            'published_at' => $this->published_at?->toISOString(),
            'has_content' => ! empty($this->exported_html),
            'grapes_json' => $this->when(
                $request->boolean('with_editor'),
                fn () => $this->grapes_json,
            ),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
