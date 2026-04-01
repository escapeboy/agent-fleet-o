<?php

namespace App\Domain\Assistant\AgentTools;

use App\Infrastructure\Auth\SanctumTokenIssuer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ManageApiTokenTool implements Tool
{
    public function name(): string
    {
        return 'manage_api_token';
    }

    public function description(): string
    {
        return 'Manage Sanctum API tokens for the current user. List tokens, create a new one (shown ONCE), or revoke by ID. Destructive for revoke.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->required()->description('Action: list, create, revoke'),
            'name' => $schema->string()->description('Token name/label (required for create)'),
            'token_id' => $schema->string()->description('Token ID to revoke (required for revoke action)'),
        ];
    }

    public function handle(Request $request): string
    {
        $user = auth()->user();

        if (! $user) {
            return json_encode(['error' => 'Not authenticated.']);
        }

        $action = $request->get('action');

        if ($action === 'list') {
            $tokens = $user->sanctumTokens()
                ->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])
                ->map(fn ($t) => [
                    'id' => $t->id,
                    'name' => $t->name,
                    'last_used_at' => $t->last_used_at?->toIso8601String(),
                    'expires_at' => $t->expires_at?->toIso8601String(),
                    'created_at' => $t->created_at->toIso8601String(),
                ]);

            return json_encode(['count' => $tokens->count(), 'tokens' => $tokens->toArray()]);
        }

        if ($action === 'create') {
            $name = $request->get('name');
            if (! $name) {
                return json_encode(['error' => 'name is required for create action.']);
            }

            $abilities = $user->is_super_admin ? ['*'] : ['team:'.$user->current_team_id];
            $expiresAt = now()->addDays(90);
            $token = SanctumTokenIssuer::create($user, $name, $abilities, $expiresAt);

            return json_encode([
                'success' => true,
                'token_id' => $token->accessToken->id,
                'name' => $name,
                'token' => $token->plainTextToken,
                'expires_at' => $expiresAt->toIso8601String(),
                'warning' => 'This token will not be shown again. Store it securely.',
            ]);
        }

        if ($action === 'revoke') {
            $tokenId = $request->get('token_id');
            if (! $tokenId) {
                return json_encode(['error' => 'token_id is required for revoke action.']);
            }

            $deleted = $user->sanctumTokens()->where('id', $tokenId)->delete();

            if (! $deleted) {
                return json_encode(['error' => "Token {$tokenId} not found."]);
            }

            return json_encode(['success' => true, 'token_id' => $tokenId, 'message' => "Token {$tokenId} revoked."]);
        }

        return json_encode(['error' => "Unknown action: {$action}. Use list, create, or revoke."]);
    }
}
