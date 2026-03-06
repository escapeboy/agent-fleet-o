<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Models\EmailTemplate;

class RenderEmailTemplateAction
{
    /**
     * Store the GrapesJS-exported inlined HTML into html_cache and activate the template.
     */
    public function execute(EmailTemplate $template, string $html, array $designJson): EmailTemplate
    {
        $template->update([
            'html_cache' => $html,
            'design_json' => $designJson,
        ]);

        return $template->fresh();
    }
}
