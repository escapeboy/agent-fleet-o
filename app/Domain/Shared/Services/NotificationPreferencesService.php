<?php

namespace App\Domain\Shared\Services;

use App\Models\User;

class NotificationPreferencesService
{
    /**
     * Default notification preferences for all users.
     * Used when a user has no saved preference for a given type.
     */
    public static function defaults(): array
    {
        return [
            'experiment.stuck' => ['in_app', 'mail', 'push'],
            'experiment.completed' => ['in_app'],
            'project.run.failed' => ['in_app', 'mail', 'push'],
            'project.run.completed' => ['in_app'],
            'project.budget.warning' => ['in_app', 'mail'],
            'project.milestone.reached' => ['in_app'],
            'agent.risk.high' => ['in_app', 'mail', 'push'],
            'approval.requested' => ['in_app', 'mail', 'push'],
            'usage.alert' => ['in_app', 'mail'],
            'weekly.digest' => ['mail'],
        ];
    }

    /**
     * Available channels per event type — constrains what can be toggled in the UI.
     */
    public static function availableChannels(): array
    {
        return [
            'experiment.stuck' => ['in_app', 'mail', 'push'],
            'experiment.completed' => ['in_app', 'mail', 'push'],
            'project.run.failed' => ['in_app', 'mail', 'push'],
            'project.run.completed' => ['in_app', 'mail', 'push'],
            'project.budget.warning' => ['in_app', 'mail', 'push'],
            'project.milestone.reached' => ['in_app', 'mail'],
            'agent.risk.high' => ['in_app', 'mail', 'push'],
            'approval.requested' => ['in_app', 'mail', 'push'],
            'usage.alert' => ['in_app', 'mail'],
            'weekly.digest' => ['mail'],
        ];
    }

    public function getForUser(User $user): array
    {
        return $user->getPreferences();
    }

    public function updateForUser(User $user, array $preferences): void
    {
        $allowed = self::availableChannels();
        $sanitized = [];

        foreach ($preferences as $type => $channels) {
            if (! isset($allowed[$type])) {
                continue;
            }
            $sanitized[$type] = array_values(
                array_intersect((array) $channels, $allowed[$type]),
            );
        }

        $user->update(['notification_preferences' => $sanitized]);
    }
}
