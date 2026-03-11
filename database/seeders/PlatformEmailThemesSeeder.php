<?php

namespace Database\Seeders;

use App\Domain\Email\Enums\EmailThemeStatus;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Models\Team;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PlatformEmailThemesSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $team = Team::withoutGlobalScopes()->where('slug', 'fleetq-platform')->first();

        if (! $team) {
            $this->command?->warn('Platform team not found. Run PlatformTeamSeeder first.');

            return;
        }

        $themes = collect();

        foreach ($this->definitions() as $def) {
            $theme = EmailTheme::withoutGlobalScopes()->updateOrCreate(
                ['team_id' => $team->id, 'name' => $def['name']],
                array_merge($def, [
                    'team_id' => $team->id,
                    'status' => EmailThemeStatus::Active,
                ]),
            );

            $themes->put($def['name'], $theme);
        }

        $this->command?->info("Platform email themes seeded: {$themes->count()}");
    }

    private function definitions(): array
    {
        return [
            [
                'name' => 'Clean & Professional',
                'background_color' => '#f4f4f4',
                'canvas_color' => '#ffffff',
                'primary_color' => '#2563eb',
                'text_color' => '#1f2937',
                'heading_color' => '#111827',
                'muted_color' => '#6b7280',
                'divider_color' => '#e5e7eb',
                'font_name' => 'Inter',
                'font_family' => 'Inter, Arial, sans-serif',
                'heading_font_size' => 24,
                'body_font_size' => 16,
                'line_height' => 1.6,
                'email_width' => 600,
                'content_padding' => 24,
                'footer_text' => 'You are receiving this email because you signed up for our service.',
                'is_system_default' => false,
            ],
            [
                'name' => 'Dark Mode',
                'background_color' => '#0f172a',
                'canvas_color' => '#1e293b',
                'primary_color' => '#6366f1',
                'text_color' => '#e2e8f0',
                'heading_color' => '#f8fafc',
                'muted_color' => '#94a3b8',
                'divider_color' => '#334155',
                'font_name' => 'Inter',
                'font_family' => 'Inter, Arial, sans-serif',
                'heading_font_size' => 24,
                'body_font_size' => 16,
                'line_height' => 1.6,
                'email_width' => 600,
                'content_padding' => 24,
                'footer_text' => 'You are receiving this email because you signed up for our service.',
                'is_system_default' => false,
            ],
            [
                'name' => 'Minimal',
                'background_color' => '#ffffff',
                'canvas_color' => '#ffffff',
                'primary_color' => '#111827',
                'text_color' => '#374151',
                'heading_color' => '#111827',
                'muted_color' => '#9ca3af',
                'divider_color' => '#f3f4f6',
                'font_name' => 'Georgia',
                'font_family' => 'Georgia, serif',
                'heading_font_size' => 22,
                'body_font_size' => 15,
                'line_height' => 1.7,
                'email_width' => 560,
                'content_padding' => 16,
                'footer_text' => 'Unsubscribe · Privacy Policy',
                'is_system_default' => false,
            ],
            [
                'name' => 'Brand-Ready',
                'background_color' => '#f9fafb',
                'canvas_color' => '#ffffff',
                'primary_color' => '#059669',
                'text_color' => '#111827',
                'heading_color' => '#111827',
                'muted_color' => '#6b7280',
                'divider_color' => '#e5e7eb',
                'font_name' => 'Inter',
                'font_family' => 'Inter, Arial, sans-serif',
                'heading_font_size' => 26,
                'body_font_size' => 16,
                'line_height' => 1.6,
                'email_width' => 640,
                'content_padding' => 32,
                'logo_width' => 180,
                'company_name' => 'Your Company',
                'footer_text' => '© 2026 Your Company · 123 Main St, City, Country · Unsubscribe',
                'is_system_default' => false,
            ],
        ];
    }
}
