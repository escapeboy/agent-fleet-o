<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

abstract class FleetQResource extends JsonResource
{
    /** @var list<string> */
    private array $invalidationTags = [];

    public function invalidates(string ...$tags): static
    {
        $this->invalidationTags = array_values(array_unique([...$this->invalidationTags, ...$tags]));

        return $this;
    }

    public function withResponse(Request $request, JsonResponse $response): void
    {
        if ($this->invalidationTags !== []) {
            $response->header('X-FleetQ-Invalidate', implode(',', $this->invalidationTags));
        }
    }
}
