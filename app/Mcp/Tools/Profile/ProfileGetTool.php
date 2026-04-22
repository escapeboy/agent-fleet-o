<?php

namespace App\Mcp\Tools\Profile;

use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ProfileGetTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'profile_get';

    protected string $description = 'Get the current authenticated user\'s profile information including name, email, 2FA status, and connected social providers.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $user = auth()->user();

        if (! $user) {
            return $this->permissionDeniedError('Not authenticated.');
        }

        $twoFactorEnabled = $user->two_factor_secret && $user->two_factor_confirmed_at;

        $connectedProviders = $user->socialAccounts()
            ->get(['provider', 'email', 'name'])
            ->map(fn ($a) => [
                'provider' => $a->provider,
                'identity' => $a->email ?? $a->name,
            ])
            ->values()
            ->toArray();

        return Response::text(json_encode([
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified' => ! is_null($user->email_verified_at),
            'has_password' => $user->password !== null,
            'two_factor_enabled' => (bool) $twoFactorEnabled,
            'connected_providers' => $connectedProviders,
            'theme' => $user->theme,
        ]));
    }
}
