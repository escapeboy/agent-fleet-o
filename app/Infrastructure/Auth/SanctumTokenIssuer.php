<?php

namespace App\Infrastructure\Auth;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Creates Sanctum personal access tokens independently of the User model's HasApiTokens trait.
 *
 * Required because the User model uses Passport's HasApiTokens for MCP OAuth2 support,
 * but the REST API still issues Sanctum tokens for the /api/v1/ endpoints.
 */
class SanctumTokenIssuer
{
    public static function create(
        Model $user,
        string $name,
        array $abilities = ['*'],
        ?DateTimeInterface $expiresAt = null,
    ): NewAccessToken {
        $prefix = config('sanctum.token_prefix', '');
        $entropy = Str::random(40);
        $plainText = $prefix.$entropy.substr(hash('crc32b', $entropy), 0, 8);

        $token = PersonalAccessToken::forceCreate([
            'tokenable_type' => $user->getMorphClass(),
            'tokenable_id' => $user->getKey(),
            'name' => $name,
            'token' => hash('sha256', $plainText),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $token->getKey().'|'.$plainText);
    }
}
