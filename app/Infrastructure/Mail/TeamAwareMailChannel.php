<?php

namespace App\Infrastructure\Mail;

use App\Domain\Email\Services\EmailThemeResolver;
use App\Domain\Shared\Models\Team;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Channels\MailChannel;

class TeamAwareMailChannel extends MailChannel
{
    protected function markdownRenderer($message): Markdown
    {
        // If the notification explicitly sets a theme, honour it
        if (! empty($message->theme)) {
            return $this->markdown->theme($message->theme);
        }

        /** @var EmailThemeResolver $resolver */
        $resolver = app(EmailThemeResolver::class);

        // Resolve team from container binding (web) or notifiable (queued)
        $team = null;
        try {
            $team = app('current.team');
            if (! $team instanceof Team) {
                $team = null;
            }
        } catch (\Throwable) {
            // Not bound in this context
        }

        $emailTheme = $team
            ? $resolver->resolveForTeam($team)
            : null;

        if ($emailTheme) {
            // Share theme variables with the mail views
            $this->markdown->view()->share('emailTheme', $emailTheme);
        }

        return $this->markdown->theme('mail.html.themes.fleetq');
    }
}
