<?php

namespace App\Livewire\Components;

use Livewire\Component;

class ThemeSwitcher extends Component
{
    public string $theme = 'default';

    public static array $themes = [
        'default' => ['label' => 'Default', 'icon' => '🔵', 'dark' => 'default-dark'],
        'catppuccin' => ['label' => 'Catppuccin', 'icon' => '🟣', 'dark' => 'catppuccin', 'light' => 'catppuccin-light'],
        'monokai' => ['label' => 'Monokai', 'icon' => '🟡', 'dark' => 'monokai', 'light' => 'monokai-light'],
        'dracula' => ['label' => 'Dracula', 'icon' => '🟪', 'dark' => 'dracula', 'light' => 'dracula-light'],
        'nord' => ['label' => 'Nord', 'icon' => '🧊', 'dark' => 'nord', 'light' => 'nord-light'],
        'solarized' => ['label' => 'Solarized', 'icon' => '🌊', 'dark' => 'solarized', 'light' => 'solarized-light'],
    ];

    public function mount(): void
    {
        $this->theme = auth()->user()->theme ?? 'default';
    }

    public function setTheme(string $theme): void
    {
        // Validate theme key exists (accept both base name and variant)
        $allValid = $this->getAllThemeKeys();
        if (! in_array($theme, $allValid)) {
            return;
        }

        $this->theme = $theme;

        if ($user = auth()->user()) {
            $user->update(['theme' => $theme]);
        }

        $this->dispatch('theme-changed', theme: $theme);
    }

    private function getAllThemeKeys(): array
    {
        $keys = [];
        foreach (static::$themes as $base => $config) {
            $keys[] = $base;
            if (isset($config['dark'])) {
                $keys[] = $config['dark'];
            }
            if (isset($config['light'])) {
                $keys[] = $config['light'];
            }
        }

        return $keys;
    }

    public function render()
    {
        return view('livewire.components.theme-switcher', [
            'themes' => static::$themes,
        ]);
    }
}
