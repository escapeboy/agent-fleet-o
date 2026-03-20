<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\Auth\SocialAccountListTool;
use App\Mcp\Tools\Auth\SocialAccountUnlinkTool;
use App\Mcp\Tools\Profile\ProfileConnectedAccountsTool;
use App\Mcp\Tools\Profile\ProfileGetTool;
use App\Mcp\Tools\Profile\ProfileTwoFactorStatusTool;
use App\Mcp\Tools\Profile\ProfileUpdateTool;

class ProfileManageTool extends CompactTool
{
    protected string $name = 'profile_manage';

    protected string $description = 'Manage user profile and account. Actions: get (profile info), update (name, email), 2fa_status (two-factor auth status), connected_accounts (list linked OAuth accounts), social_list (list social accounts), social_unlink (provider — unlink social account).';

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
