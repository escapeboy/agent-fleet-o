<?php

namespace App\Http\Controllers;

use App\Domain\Email\Models\EmailTemplate;
use Illuminate\Http\Response;

class EmailTemplatePreviewController extends Controller
{
    public function show(EmailTemplate $template): Response
    {
        abort_unless($template->html_cache, 404, 'Template has not been saved yet. Open the builder and save first.');

        return response($template->html_cache, 200, [
            'Content-Type' => 'text/html; charset=utf-8',
        ]);
    }
}
