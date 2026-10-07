<?php

namespace App\Domain\Shared\Services;

use App\Domain\Shared\Models\Team;
use InvalidArgumentException;

/**
 * Per-team on/off choice for the platform features listed in
 * config/team_features.php. A feature runs for a team only when the platform
 * switch is on AND the team has not turned it off (or, for opt-in features,
 * has turned it on).
 *
 * Bound as a scoped singleton: the team lookup memo lives for one request or
 * one queued job, so a long-lived worker never serves a stale choice.
 */
class TeamFeatures
{
    /** @var array<string, Team|null> */
    private array $teams = [];

    /**
     * @return array<string, array<string, mixed>>
     */
    public function definitions(): array
    {
        return config('team_features', []);
    }

    public function exists(string $key): bool
    {
        return array_key_exists($key, $this->definitions());
    }

    public function platformEnabled(string $key): bool
    {
        $path = $this->definition($key)['platform'] ?? null;

        return $path === null || (bool) config($path, false);
    }

    /**
     * Whether the feature runs for the given team. Without a team (system
     * jobs, platform-level calls) the definition default applies.
     */
    public function enabled(string $key, Team|string|null $team): bool
    {
        if (! $this->platformEnabled($key)) {
            return false;
        }

        $team = $this->resolveTeam($team);
        $default = (bool) ($this->definition($key)['default'] ?? true);

        if ($team === null) {
            return $default;
        }

        return $this->teamChoice($team, $key) ?? $default;
    }

    /**
     * The team's explicit choice, or null when it never chose.
     */
    public function teamChoice(Team $team, string $key): ?bool
    {
        $value = data_get($team->settings ?? [], $this->settingKey($key));

        return $value === null ? null : (bool) $value;
    }

    public function set(Team $team, string $key, bool $enabled): void
    {
        $settings = $team->settings ?? [];
        data_set($settings, $this->settingKey($key), $enabled);
        $team->update(['settings' => $settings]);

        $this->teams[(string) $team->getKey()] = $team;
    }

    /**
     * Every feature with the platform state, the team's choice and the result.
     *
     * @return list<array{key: string, label: string, group: string, description: string, platform_enabled: bool, team_choice: bool|null, default: bool, enabled: bool}>
     */
    public function overview(Team $team): array
    {
        $rows = [];

        foreach ($this->definitions() as $key => $def) {
            $rows[] = [
                'key' => $key,
                'label' => (string) $def['label'],
                'group' => (string) ($def['group'] ?? 'Other'),
                'description' => (string) ($def['description'] ?? ''),
                'platform_enabled' => $this->platformEnabled($key),
                'team_choice' => $this->teamChoice($team, $key),
                'default' => (bool) ($def['default'] ?? true),
                'enabled' => $this->enabled($key, $team),
            ];
        }

        return $rows;
    }

    public function settingKey(string $key): string
    {
        return (string) ($this->definition($key)['setting'] ?? "features.{$key}");
    }

    /**
     * @return array<string, mixed>
     */
    private function definition(string $key): array
    {
        return $this->definitions()[$key]
            ?? throw new InvalidArgumentException("Unknown team feature: {$key}");
    }

    private function resolveTeam(Team|string|null $team): ?Team
    {
        if ($team instanceof Team || $team === null || $team === '') {
            return $team ?: null;
        }

        if (! array_key_exists($team, $this->teams)) {
            $this->teams[$team] = Team::withoutGlobalScopes()->find($team);
        }

        return $this->teams[$team];
    }
}
