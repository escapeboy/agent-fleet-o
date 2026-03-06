<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Enums\EmailThemeStatus;
use App\Domain\Email\Models\EmailTheme;
use App\Domain\Shared\Models\Team;

class CreateEmailThemeAction
{
    public function execute(Team $team, array $data): EmailTheme
    {
        return EmailTheme::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'name' => $data['name'],
            'status' => $data['status'] ?? EmailThemeStatus::Draft,
            'logo_url' => $data['logo_url'] ?? null,
            'logo_width' => $data['logo_width'] ?? 150,
            'background_color' => $data['background_color'] ?? '#f4f4f4',
            'canvas_color' => $data['canvas_color'] ?? '#ffffff',
            'primary_color' => $data['primary_color'] ?? '#2563eb',
            'text_color' => $data['text_color'] ?? '#1f2937',
            'heading_color' => $data['heading_color'] ?? '#111827',
            'muted_color' => $data['muted_color'] ?? '#6b7280',
            'divider_color' => $data['divider_color'] ?? '#e5e7eb',
            'font_name' => $data['font_name'] ?? 'Inter',
            'font_url' => $data['font_url'] ?? null,
            'font_family' => $data['font_family'] ?? 'Inter, Arial, sans-serif',
            'heading_font_size' => $data['heading_font_size'] ?? 24,
            'body_font_size' => $data['body_font_size'] ?? 16,
            'line_height' => $data['line_height'] ?? 1.6,
            'email_width' => $data['email_width'] ?? 600,
            'content_padding' => $data['content_padding'] ?? 24,
            'company_name' => $data['company_name'] ?? null,
            'company_address' => $data['company_address'] ?? null,
            'footer_text' => $data['footer_text'] ?? null,
            'is_system_default' => $data['is_system_default'] ?? false,
        ]);
    }
}
