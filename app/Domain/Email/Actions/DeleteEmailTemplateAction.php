<?php

namespace App\Domain\Email\Actions;

use App\Domain\Email\Models\EmailTemplate;

class DeleteEmailTemplateAction
{
    public function execute(EmailTemplate $template): void
    {
        $template->delete();
    }
}
