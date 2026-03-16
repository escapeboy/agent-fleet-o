<?php

namespace App\Mcp\Tools\Profile;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class ProfileTwoFactorStatusTool extends Tool
{
    protected string $name = 'profile_2fa_status';

    protected string $description = 'Get the current user\'s two-factor authentication status. Returns: disabled, enabling (set up but not confirmed), or enabled.';

    public function schema(JsonSchema $schema): array
    {
        return [];
    }

    public function handle(Request $request): Response
    {
        $user = auth()->user();

        if (! $user) {
            return Response::error('Not authenticated.');
        }

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $state = 'enabled';
        } elseif ($user->two_factor_secret) {
            $state = 'enabling';
        } else {
            $state = 'disabled';
        }

        return Response::text(json_encode([
            'state' => $state,
            'confirmed_at' => $user->two_factor_confirmed_at?->toIso8601String(),
        ]));
    }
}
