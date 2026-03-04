<?php

namespace App\Infrastructure\Encryption\Casts;

use App\Infrastructure\Encryption\CredentialEncryption;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast that encrypts/decrypts string data using per-team envelope
 * encryption (XSalsa20-Poly1305 under a team-specific DEK).
 *
 * Stores the value as a JSON envelope: {"_s": "<plaintext string>"}, encrypted
 * with the team's DEK (v2) or APP_KEY fallback (v1).
 *
 * Handles three decryption formats for backward compatibility:
 *   v2  – team-DEK XSalsa20 envelope ({"v":2,"n":...,"c":...})
 *   v1  – APP_KEY encrypted JSON string ({"_s":"..."} or bare string via json_encode)
 *   v0  – Legacy PHP-serialized APP_KEY (from Eloquent `encrypted` cast on older records)
 *
 * Usage in model casts:
 *   'bot_token'      => TeamEncryptedString::class,
 *   'webhook_secret' => TeamEncryptedString::class,
 */
class TeamEncryptedString implements CastsAttributes
{
    /**
     * Decrypt the stored value to a plain PHP string.
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $teamId = $this->resolveTeamId($model, $attributes);

        try {
            $decrypted = app(CredentialEncryption::class)->decrypt($value, $teamId);

            // v2 / v1-JSON: CredentialEncryption returns the JSON-decoded value.
            // We store strings as {"_s": "<value>"}.
            if (is_array($decrypted)) {
                return isset($decrypted['_s']) ? (string) $decrypted['_s'] : null;
            }

            // v1-JSON bare string: json_encode("abc") → "\"abc\"" → json_decode → "abc"
            if (is_string($decrypted)) {
                return $decrypted;
            }
        } catch (\Throwable) {
            // Fall through to v0 PHP-serialized legacy path
        }

        // v0 — PHP-serialized via Laravel's `encrypted` cast (no serialize=false flag).
        // This handles bot_token values stored before the TeamEncryptedString cast was introduced.
        try {
            return app('encrypter')->decrypt($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Encrypt the plain PHP string using the team's encryption key.
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $teamId = $this->resolveTeamId($model, $attributes);

        // Wrap string in array so CredentialEncryption can JSON-encode it.
        return app(CredentialEncryption::class)->encrypt(['_s' => (string) $value], $teamId);
    }

    /**
     * Resolve team_id from the model or raw attributes.
     * During set(), the model may not have team_id yet (it is still in $attributes).
     */
    private function resolveTeamId(Model $model, array $attributes): ?string
    {
        return $model->team_id
            ?? $attributes['team_id']
            ?? null;
    }
}
