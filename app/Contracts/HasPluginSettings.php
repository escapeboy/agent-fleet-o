<?php

namespace App\Contracts;

use Livewire\Component;

/**
 * Optional interface for plugins that expose a settings UI.
 *
 * Implement this on your FleetPlugin class.
 * The GlobalSettingsPage will render a tab for your plugin's settings component.
 */
interface HasPluginSettings
{
    /**
     * Return the fully-qualified class name of the Livewire component
     * that renders this plugin's settings form.
     *
     * @return class-string<Component>
     */
    public function settingsComponent(): string;
}
