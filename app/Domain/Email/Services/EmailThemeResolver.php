<?php

namespace App\Domain\Email\Services;

use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Project\Models\Project;
use App\Domain\Shared\Models\Team;

class EmailThemeResolver
{
    /**
     * Resolve the active email theme for a team.
     * Returns null when the team has no active theme (falls back to Laravel default).
     */
    public function resolveForTeam(?Team $team): ?EmailTheme
    {
        if (! $team) {
            return null;
        }

        // 1. Use the team's explicitly set default theme
        if ($team->default_email_theme_id) {
            $theme = EmailTheme::withoutGlobalScopes()
                ->where('id', $team->default_email_theme_id)
                ->where('team_id', $team->id)
                ->first();

            if ($theme) {
                return $theme;
            }
        }

        // 2. Fall back to the first active theme for this team
        return EmailTheme::withoutGlobalScopes()
            ->where('team_id', $team->id)
            ->where('status', 'active')
            ->oldest()
            ->first();
    }

    /**
     * Resolve the email template for a project.
     * Falls back to null when no template is assigned or template has no rendered HTML.
     */
    public function resolveForProject(?Project $project): ?EmailTemplate
    {
        if (! $project || ! $project->email_template_id) {
            return null;
        }

        $template = EmailTemplate::withoutGlobalScopes()
            ->where('id', $project->email_template_id)
            ->where('team_id', $project->team_id)
            ->first();

        return ($template && $template->html_cache) ? $template : null;
    }

    /**
     * Resolve theme for a queued notification context where the notifiable has a team.
     */
    public function resolveForNotifiable(mixed $notifiable): ?EmailTheme
    {
        // Try current.team binding first (web/sync context)
        try {
            $team = app('current.team');
            if ($team instanceof Team) {
                return $this->resolveForTeam($team);
            }
        } catch (\Throwable) {
            // Not bound — likely a queued job context
        }

        // Fall back to notifiable's team relationship
        if ($notifiable && method_exists($notifiable, 'currentTeam')) {
            return $this->resolveForTeam($notifiable->currentTeam);
        }

        if ($notifiable && isset($notifiable->team)) {
            return $this->resolveForTeam($notifiable->team);
        }

        return null;
    }
}
