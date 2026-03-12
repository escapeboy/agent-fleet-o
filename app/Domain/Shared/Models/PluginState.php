<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persists enabled/disabled state and settings for installed plugins.
 *
 * Two scopes:
 *   team_id = NULL  → global platform record (self-hosted; read by isEnabled())
 *   team_id = uuid  → per-team override (cloud; read by isEnabledForTeam())
 *
 * @property string $id
 * @property string|null $team_id
 * @property string $plugin_id
 * @property string $name
 * @property string $version
 * @property bool $enabled
 * @property array|null $settings
 * @property Carbon|null $installed_at
 */
class PluginState extends Model
{
    use HasUuids;

    protected $fillable = [
        'team_id',
        'plugin_id',
        'name',
        'version',
        'enabled',
        'settings',
        'installed_at',
    ];

    protected $casts = [
        'enabled' => 'boolean',
        'settings' => 'array',
        'installed_at' => 'datetime',
    ];

    // -------------------------------------------------------------------------
    // Global (self-hosted) helpers — team_id = NULL
    // -------------------------------------------------------------------------

    /**
     * Check whether a plugin is globally enabled.
     * Used by self-hosted installations and FleetPluginServiceProvider.
     */
    public static function isEnabled(string $pluginId, bool $default = true): bool
    {
        $state = static::whereNull('team_id')
            ->where('plugin_id', $pluginId)
            ->first();

        return $state ? $state->enabled : $default;
    }

    /**
     * Register (upsert) a global plugin state row on first boot.
     */
    public static function register(string $pluginId, string $name, string $version): self
    {
        return static::firstOrCreate(
            ['plugin_id' => $pluginId, 'team_id' => null],
            [
                'name' => $name,
                'version' => $version,
                'enabled' => true,
                'installed_at' => now(),
            ],
        );
    }

    // -------------------------------------------------------------------------
    // Per-team (cloud) helpers — team_id = uuid
    // -------------------------------------------------------------------------

    /**
     * Check whether a plugin is enabled for a specific team.
     *
     * Falls back to $default when no per-team row exists.
     * This lets the cloud operator decide whether plugins are opt-in (false)
     * or opt-out (true) by default.
     */
    public static function isEnabledForTeam(string $pluginId, string $teamId, bool $default = true): bool
    {
        $state = static::where('team_id', $teamId)
            ->where('plugin_id', $pluginId)
            ->first();

        return $state ? $state->enabled : $default;
    }

    /**
     * Upsert a per-team plugin state row.
     * Used by the cloud admin when a team first overrides a plugin's state.
     */
    public static function registerForTeam(
        string $pluginId,
        string $name,
        string $version,
        string $teamId,
        bool $enabled = true,
    ): self {
        return static::firstOrCreate(
            ['plugin_id' => $pluginId, 'team_id' => $teamId],
            [
                'name' => $name,
                'version' => $version,
                'enabled' => $enabled,
                'installed_at' => now(),
            ],
        );
    }
}
