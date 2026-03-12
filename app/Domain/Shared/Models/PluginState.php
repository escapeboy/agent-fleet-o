<?php

namespace App\Domain\Shared\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Persists enabled/disabled state and settings for installed plugins.
 *
 * @property string $id
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

    /**
     * Check whether a plugin is enabled, with a default fallback.
     */
    public static function isEnabled(string $pluginId, bool $default = true): bool
    {
        $state = static::where('plugin_id', $pluginId)->first();

        return $state ? $state->enabled : $default;
    }

    /**
     * Register a plugin the first time it boots (upsert).
     */
    public static function register(string $pluginId, string $name, string $version): self
    {
        return static::firstOrCreate(
            ['plugin_id' => $pluginId],
            [
                'name' => $name,
                'version' => $version,
                'enabled' => true,
                'installed_at' => now(),
            ],
        );
    }
}
