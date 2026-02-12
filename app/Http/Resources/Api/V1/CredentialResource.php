<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CredentialResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'credential_type' => $this->credential_type->value,
            'status' => $this->status->value,
            'metadata' => $this->metadata,
            'expires_at' => $this->expires_at?->toISOString(),
            'is_expired' => $this->isExpired(),
            'last_used_at' => $this->last_used_at?->toISOString(),
            'last_rotated_at' => $this->last_rotated_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
