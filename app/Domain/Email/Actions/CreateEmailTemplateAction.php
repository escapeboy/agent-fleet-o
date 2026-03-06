<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Shared\Models\Team;

class CreateEmailTemplateAction
{
    public function execute(Team $team, array $data): EmailTemplate
    {
        return EmailTemplate::withoutGlobalScopes()->create([
            'team_id' => $team->id,
            'email_theme_id' => $data['email_theme_id'] ?? null,
            'name' => $data['name'],
            'subject' => $data['subject'] ?? null,
            'preview_text' => $data['preview_text'] ?? null,
            'design_json' => $data['design_json'] ?? [],
            'html_cache' => $data['html_cache'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'visibility' => $data['visibility'] ?? 'private',
        ]);
    }
}
