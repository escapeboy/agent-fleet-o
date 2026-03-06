<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Models\Team;

class DeleteEmailThemeAction
{
    public function execute(EmailTheme $theme): void
    {
        // Clear from team default if this theme was the default
        Team::withoutGlobalScopes()
            ->where('default_email_theme_id', $theme->id)
            ->update(['default_email_theme_id' => null]);

        $theme->delete();
    }
}
