<?php

namespace App\Domain\Credential\Actions;

use App\Domain\Credential\Models\Credential;

class RotateCredentialSecretAction
{
    public function execute(Credential $credential, array $newSecretData): Credential
    {
        $credential->update([
            'secret_data' => $newSecretData,
            'last_rotated_at' => now(),
        ]);

        return $credential->fresh();
    }
}
