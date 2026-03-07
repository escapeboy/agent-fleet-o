<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Models\EmailTheme;

class UpdateEmailThemeAction
{
    public function execute(EmailTheme $theme, array $data): EmailTheme
    {
        $theme->update([
            'name' => $data['name'] ?? $theme->name,
            'status' => $data['status'] ?? $theme->status,
            'logo_url' => array_key_exists('logo_url', $data) ? $data['logo_url'] : $theme->logo_url,
            'logo_width' => $data['logo_width'] ?? $theme->logo_width,
            'background_color' => $data['background_color'] ?? $theme->background_color,
            'canvas_color' => $data['canvas_color'] ?? $theme->canvas_color,
            'primary_color' => $data['primary_color'] ?? $theme->primary_color,
            'text_color' => $data['text_color'] ?? $theme->text_color,
            'heading_color' => $data['heading_color'] ?? $theme->heading_color,
            'muted_color' => $data['muted_color'] ?? $theme->muted_color,
            'divider_color' => $data['divider_color'] ?? $theme->divider_color,
            'font_name' => $data['font_name'] ?? $theme->font_name,
            'font_url' => array_key_exists('font_url', $data) ? $data['font_url'] : $theme->font_url,
            'font_family' => $data['font_family'] ?? $theme->font_family,
            'heading_font_size' => $data['heading_font_size'] ?? $theme->heading_font_size,
            'body_font_size' => $data['body_font_size'] ?? $theme->body_font_size,
            'line_height' => $data['line_height'] ?? $theme->line_height,
            'email_width' => $data['email_width'] ?? $theme->email_width,
            'content_padding' => $data['content_padding'] ?? $theme->content_padding,
            'company_name' => array_key_exists('company_name', $data) ? $data['company_name'] : $theme->company_name,
            'company_address' => array_key_exists('company_address', $data) ? $data['company_address'] : $theme->company_address,
            'footer_text' => array_key_exists('footer_text', $data) ? $data['footer_text'] : $theme->footer_text,
        ]);

        return $theme->fresh();
    }
}
