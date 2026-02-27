<?php

namespace App\Http\Resources\Api\V1;

use App\Domain\Tool\Models\SshHostFingerprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SshHostFingerprint */
class SshFingerprintResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'host' => $this->host,
            'port' => $this->port,
            'fingerprint_sha256' => $this->fingerprint_sha256,
            'verified_at' => $this->verified_at?->toISOString(),
            'created_at' => $this->created_at->toISOString(),
        ];
    }
}
