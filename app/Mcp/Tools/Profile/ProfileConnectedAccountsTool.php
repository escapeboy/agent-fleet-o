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
class ProfileConnectedAccountsTool extends Tool
{
    protected string $name = 'profile_connected_accounts';

    protected string $description = 'List social providers connected to the current user\'s account (Google, GitHub, LinkedIn, X, Apple).';

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

        $supportedProviders = ['google', 'github', 'linkedin-openid', 'x', 'apple'];

        $connected = $user->socialAccounts()->get()->keyBy('provider');

        $result = array_map(fn ($provider) => [
            'provider' => $provider,
            'connected' => isset($connected[$provider]),
            'identity' => $connected[$provider]?->email ?? $connected[$provider]?->name,
            'connected_at' => $connected[$provider]?->created_at?->toIso8601String(),
        ], $supportedProviders);

        return Response::text(json_encode($result));
    }
}
