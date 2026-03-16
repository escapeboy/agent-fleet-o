<?php

namespace App\Mcp\Tools\Auth;

use App\Domain\Shared\Models\UserSocialAccount;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class SocialAccountListTool extends Tool
{
    protected string $name = 'social_account_list';

    protected string $description = 'List the social login providers connected to the current user account (Google, GitHub, LinkedIn, X, Apple).';

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

        $accounts = UserSocialAccount::where('user_id', $user->id)
            ->orderBy('provider')
            ->get()
            ->map(fn ($account) => [
                'provider'         => $account->provider,
                'provider_user_id' => $account->provider_user_id,
                'email'            => $account->email,
                'name'             => $account->name,
                'avatar'           => $account->avatar,
                'connected_at'     => $account->created_at->toIso8601String(),
            ])
            ->values();

        return Response::text(json_encode([
            'connected_providers' => $accounts,
            'has_password'        => ! empty($user->password),
        ]));
    }
}
