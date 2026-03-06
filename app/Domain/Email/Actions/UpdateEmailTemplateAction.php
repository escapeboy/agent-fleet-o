<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Models\EmailTemplate;

class UpdateEmailTemplateAction
{
    public function execute(EmailTemplate $template, array $data): EmailTemplate
    {
        $template->update([
            'email_theme_id' => array_key_exists('email_theme_id', $data) ? $data['email_theme_id'] : $template->email_theme_id,
            'name' => $data['name'] ?? $template->name,
            'subject' => array_key_exists('subject', $data) ? $data['subject'] : $template->subject,
            'preview_text' => array_key_exists('preview_text', $data) ? $data['preview_text'] : $template->preview_text,
            'design_json' => $data['design_json'] ?? $template->design_json,
            'html_cache' => array_key_exists('html_cache', $data) ? $data['html_cache'] : $template->html_cache,
            'status' => $data['status'] ?? $template->status,
            'visibility' => $data['visibility'] ?? $template->visibility,
        ]);

        return $template->fresh();
    }
}
