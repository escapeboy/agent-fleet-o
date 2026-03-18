<?php

namespace App\Mcp\Tools\Admin;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Services\DeploymentMode;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AdminUserRevokeSessionsTool extends Tool
{
    protected string $name = 'admin_user_revoke_sessions';

    protected string $description = 'Revoke all API tokens for a user, effectively forcing them to re-authenticate. Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()
                ->description('UUID of the user whose tokens to revoke')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        if (app(DeploymentMode::class)->isCloud() && ! auth()->user()?->is_super_admin) {
            return Response::error('Access denied: super admin privileges required.');
        }

        $user = User::findOrFail($request->get('user_id'));
        $count = $user->tokens()->count();
        $user->tokens()->delete();

        AuditEntry::withoutGlobalScopes()->create([
            'user_id' => auth()->id(),
            'event' => 'user.tokens_revoked',
            'subject_type' => User::class,
            'subject_id' => $user->id,
            'properties' => [
                'email' => $user->email,
                'tokens_revoked' => $count,
                'triggered_by' => 'mcp_admin',
            ],
            'triggered_by' => 'super_admin',
            'created_at' => now(),
        ]);

        return Response::text(json_encode([
            'success' => true,
            'message' => "{$count} token(s) revoked for user {$user->email}.",
            'tokens_revoked' => $count,
        ]));
    }
}
