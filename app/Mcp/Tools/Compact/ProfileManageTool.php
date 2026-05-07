<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Auth\SocialAccountListTool;
use App\Mcp\Tools\Auth\SocialAccountUnlinkTool;
use App\Mcp\Tools\Profile\ProfileConnectedAccountsTool;
use App\Mcp\Tools\Profile\ProfileGetTool;
use App\Mcp\Tools\Profile\ProfileTwoFactorStatusTool;
use App\Mcp\Tools\Profile\ProfileUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class ProfileManageTool extends CompactTool
{
    protected string $name = 'profile_manage';

    protected string $description = <<<'TXT'
Current authenticated user's profile and account settings. Operates on the caller — there is no `user_id` parameter; super-admins managing other users use `admin_manage` instead.

Actions:
- get (read) — profile info (name, email, locale, current team, last login).
- update (write) — any of: name, email, locale, timezone. Email change triggers re-verification.
- 2fa_status (read) — whether TOTP is enrolled and recovery codes remain.
- connected_accounts (read) — list of linked OAuth providers (Google, GitHub, etc.).
- social_list (read) — duplicate of connected_accounts kept for client compat.
- social_unlink (DESTRUCTIVE) — provider. Removes the OAuth link; user must keep at least one auth method (password or another provider).
TXT;

    protected function toolMap(): array
    {
        return [
            'get' => ProfileGetTool::class,
            'update' => ProfileUpdateTool::class,
            '2fa_status' => ProfileTwoFactorStatusTool::class,
            'connected_accounts' => ProfileConnectedAccountsTool::class,
            'social_list' => SocialAccountListTool::class,
            'social_unlink' => SocialAccountUnlinkTool::class,
        ];
    }
}
