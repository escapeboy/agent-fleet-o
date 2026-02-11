<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MarketplaceListingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'readme' => $this->readme,
            'type' => $this->type,
            'category' => $this->category,
            'tags' => $this->tags,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'version' => $this->version,
            'install_count' => $this->install_count,
            'avg_rating' => $this->avg_rating,
            'review_count' => $this->review_count,
            'published_by' => $this->published_by,
            'team_id' => $this->team_id,
            'reviews' => $this->whenLoaded('reviews', fn () => $this->reviews->map(fn ($r) => [
                'id' => $r->id,
                'rating' => $r->rating,
                'comment' => $r->comment,
                'user_id' => $r->user_id,
                'created_at' => $r->created_at->toISOString(),
            ])),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
