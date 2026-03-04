<?php

namespace App\Infrastructure\Encryption\Casts;

use App\Infrastructure\Encryption\CredentialEncryption;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast that encrypts/decrypts array data using a per-team
 * encryption key (envelope encryption). Falls back to Laravel's
 * APP_KEY-based encryption for teams without a dedicated key or
 * for legacy data.
 *
 * Usage in model casts:
 *   'secret_data' => TeamEncryptedArray::class,
 */
class TeamEncryptedArray implements CastsAttributes
{
    /**
     * Decrypt the stored value using the team's encryption key.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $teamId = $this->resolveTeamId($model, $attributes);

        return app(CredentialEncryption::class)->decrypt($value, $teamId);
    }

    /**
     * Encrypt the value using the team's encryption key.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $value = json_decode($value, true) ?? [];
        }

        $teamId = $this->resolveTeamId($model, $attributes);

        return app(CredentialEncryption::class)->encrypt($value, $teamId);
    }

    /**
     * Resolve team_id from the model or raw attributes.
     * During set(), the model may not have team_id yet (it's in $attributes).
     */
    private function resolveTeamId(Model $model, array $attributes): ?string
    {
        return $model->team_id
            ?? $attributes['team_id']
            ?? null;
    }
}
