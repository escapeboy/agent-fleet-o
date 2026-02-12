<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Enums\CredentialStatus;
use App\Domain\Credential\Models\Credential;
use Illuminate\Support\Str;

class UpdateCredentialAction
{
    /**
     * Updates credential metadata only. Does NOT touch secret_data.
     * Use RotateCredentialSecretAction to change secrets.
     */
    public function execute(
        Credential $credential,
        ?string $name = null,
        ?string $description = null,
        ?CredentialStatus $status = null,
        ?array $metadata = null,
        ?string $expiresAt = null,
    ): Credential {
        $data = array_filter([
            'name' => $name,
            'slug' => $name ? Str::slug($name) : null,
            'description' => $description,
            'status' => $status,
            'expires_at' => $expiresAt,
        ], fn ($v) => $v !== null);

        if ($metadata !== null) {
            $data['metadata'] = $metadata;
        }

        $credential->update($data);

        return $credential->fresh();
    }
}
