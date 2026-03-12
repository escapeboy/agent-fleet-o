<?php

namespace App\Livewire\Hooks;

use Livewire\ComponentHook;

/**
 * Global Livewire lifecycle hook that lets plugins react to component events.
 *
 * Plugins listen to Laravel events dispatched here to integrate with the UI
 * lifecycle without modifying any core Livewire components.
 *
 * Registration (in AppServiceProvider::boot):
 *   \Livewire\Livewire::componentHook(PluginDispatchHook::class);
 */
class PluginDispatchHook extends ComponentHook
{
    public function hydrate(): void
    {
        event('fleet.component.hydrate', ['component' => $this->component]);
    }

    public function render($view): void
    {
        event('fleet.component.render', ['component' => $this->component, 'view' => $view]);
    }
}
