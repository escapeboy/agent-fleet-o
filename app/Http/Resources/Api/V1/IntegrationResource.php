<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntegrationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $meta = (array) ($this->meta ?? []);

        return [
            'id' => $this->id,
            'driver' => $this->driver,
            'name' => $this->name,
            'credential_id' => $this->credential_id,
            'status' => $this->status->value,
            'config' => $this->config,
            'meta' => $meta,
            'account' => $meta['account'] ?? null,
            'last_pinged_at' => $this->last_pinged_at?->toISOString(),
            'last_ping_status' => $this->last_ping_status,
            'last_ping_message' => $this->last_ping_message,
            'error_count' => $this->error_count,
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
