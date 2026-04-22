<?php

namespace App\Mcp\Tools\Shared;

use App\Infrastructure\Auth\SanctumTokenIssuer;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class ApiTokenManageTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'api_token_manage';

    protected string $description = 'Manage Sanctum API tokens for the current user. List active tokens, create a new token (returned ONCE, never again), or revoke a token by ID.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()
                ->description('Action to perform: list, create, revoke')
                ->enum(['list', 'create', 'revoke'])
                ->required(),
            'name' => $schema->string()
                ->description('Token name/label (required for create action)'),
            'token_id' => $schema->integer()
                ->description('Token ID to revoke (required for revoke action)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = Auth::user();

        if (! $user) {
            return $this->permissionDeniedError('Not authenticated.');
        }

        $action = $request->get('action');

        return match ($action) {
            'list' => $this->listTokens($user),
            'create' => $this->createToken($user, $request),
            'revoke' => $this->revokeToken($user, $request),
            default => $this->invalidArgumentError("Unknown action: {$action}"),
        };
    }

    private function listTokens($user): Response
    {
        $tokens = $user->sanctumTokens()
            ->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at'])
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'expires_at' => $t->expires_at?->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
            ]);

        return Response::text(json_encode([
            'count' => $tokens->count(),
            'tokens' => $tokens->toArray(),
        ]));
    }

    private function createToken($user, Request $request): Response
    {
        $name = $request->get('name');

        if (! $name) {
            return $this->invalidArgumentError('name is required for create action.');
        }

        $abilities = $user->is_super_admin ? ['*'] : ['team:'.$user->current_team_id];
        $expiresAt = now()->addDays(90);
        $token = SanctumTokenIssuer::create($user, $name, $abilities, $expiresAt);

        // The plaintext token is returned only once and must not be logged
        return Response::text(json_encode([
            'success' => true,
            'token_id' => $token->accessToken->id,
            'name' => $name,
            'token' => $token->plainTextToken,
            'expires_at' => $expiresAt->toIso8601String(),
            'warning' => 'This token will not be shown again. Store it securely.',
        ]));
    }

    private function revokeToken($user, Request $request): Response
    {
        $tokenId = $request->get('token_id');

        if (! $tokenId) {
            return $this->invalidArgumentError('token_id is required for revoke action. Use the list action to find token IDs.');
        }

        $deleted = $user->sanctumTokens()->where('id', $tokenId)->delete();

        if (! $deleted) {
            return $this->notFoundError('token', $tokenId);
        }

        return Response::text(json_encode([
            'success' => true,
            'token_id' => $tokenId,
            'message' => "Token {$tokenId} has been revoked.",
        ]));
    }
}
