<?php

namespace App\Mcp\Tools\Admin;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Password;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class AdminUserSendPasswordResetTool extends Tool
{
    protected string $name = 'admin_user_send_password_reset';

    protected string $description = 'Send a password reset email to a specific user. Super admin only.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'user_id' => $schema->string()
                ->description('UUID of the user')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $user = User::findOrFail($request->get('user_id'));
        $status = Password::sendResetLink(['email' => $user->email]);

        return Response::text(json_encode([
            'success' => $status === Password::RESET_LINK_SENT,
            'message' => $status === Password::RESET_LINK_SENT
                ? "Password reset email sent to {$user->email}."
                : "Failed to send reset email: {$status}",
            'status' => $status,
        ]));
    }
}
