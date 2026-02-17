<?php

namespace Tests\Unit;

use App\Livewire\Components\ThemeSwitcher;
use App\Models\User;
use PHPUnit\Framework\TestCase;

class ThemeSwitcherTest extends TestCase
{
    public function test_theme_list_has_six_themes(): void
    {
        $themes = ThemeSwitcher::$themes;

        $this->assertCount(6, $themes);
        $this->assertArrayHasKey('default', $themes);
        $this->assertArrayHasKey('catppuccin', $themes);
        $this->assertArrayHasKey('monokai', $themes);
        $this->assertArrayHasKey('dracula', $themes);
        $this->assertArrayHasKey('nord', $themes);
        $this->assertArrayHasKey('solarized', $themes);
    }

    public function test_each_theme_has_label_and_dark_variant(): void
    {
        foreach (ThemeSwitcher::$themes as $key => $config) {
            $this->assertArrayHasKey('label', $config, "Theme '{$key}' missing label");
            $this->assertArrayHasKey('icon', $config, "Theme '{$key}' missing icon");
            $this->assertArrayHasKey('dark', $config, "Theme '{$key}' missing dark variant");
        }
    }

    public function test_user_model_has_theme_fillable(): void
    {
        $user = new User;

        $this->assertContains('theme', $user->getFillable());
    }

    public function test_default_theme_has_dark_variant(): void
    {
        $config = ThemeSwitcher::$themes['default'];

        $this->assertEquals('default-dark', $config['dark']);
    }
}
