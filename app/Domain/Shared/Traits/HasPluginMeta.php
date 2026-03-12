<?php

namespace App\Domain\Shared\Traits;

/**
 * Provides plugin-namespaced metadata storage on any model with a `meta` JSONB column.
 *
 * Usage:
 *   $agent->setPluginMeta('my-plugin', 'key', 'value');
 *   $value = $agent->getPluginMeta('my-plugin', 'key');
 *   $all   = $agent->allPluginMeta('my-plugin');
 *   $agent->forgetPluginMeta('my-plugin', 'key');
 *
 * Each plugin's data is isolated under its own namespace key in the `meta` JSONB column.
 * Plugins cannot accidentally overwrite each other's data as long as they use unique IDs.
 */
trait HasPluginMeta
{
    /**
     * Store a value under the plugin's namespace in the meta column.
     */
    public function setPluginMeta(string $pluginId, string $key, mixed $value): void
    {
        $meta = $this->meta ?? [];
        $meta[$pluginId][$key] = $value;
        $this->forceFill(['meta' => $meta])->save();
    }

    /**
     * Retrieve a value from the plugin's namespace, with optional default.
     */
    public function getPluginMeta(string $pluginId, string $key, mixed $default = null): mixed
    {
        return data_get($this->meta ?? [], "{$pluginId}.{$key}", $default);
    }

    /**
     * Retrieve all metadata stored by a plugin.
     *
     * @return array<string, mixed>
     */
    public function allPluginMeta(string $pluginId): array
    {
        return ($this->meta ?? [])[$pluginId] ?? [];
    }

    /**
     * Remove a specific key from the plugin's namespace.
     */
    public function forgetPluginMeta(string $pluginId, string $key): void
    {
        $meta = $this->meta ?? [];
        unset($meta[$pluginId][$key]);
        $this->forceFill(['meta' => $meta])->save();
    }

    /**
     * Remove all metadata stored by a plugin.
     */
    public function clearPluginMeta(string $pluginId): void
    {
        $meta = $this->meta ?? [];
        unset($meta[$pluginId]);
        $this->forceFill(['meta' => $meta])->save();
    }
}
