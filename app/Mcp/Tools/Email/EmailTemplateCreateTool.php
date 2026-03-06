<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Actions\CreateEmailTemplateAction;
use App\Domain\Email\Services\MjmlRenderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class EmailTemplateCreateTool extends Tool
{
    protected string $name = 'email_template_create';

    protected string $description = 'Create a new email template for the current team. Optionally provide html_body (raw HTML) or mjml_body (MJML markup — compiled server-side) to set content immediately.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Template name')->required(),
            'subject' => $schema->string()->description('Email subject line'),
            'preview_text' => $schema->string()->description('Short preview text shown in email clients'),
            'status' => $schema->string()
                ->description('Status: draft, active, archived (default: draft)')
                ->enum(['draft', 'active', 'archived']),
            'visibility' => $schema->string()
                ->description('Visibility: private, public (default: private)')
                ->enum(['private', 'public']),
            'email_theme_id' => $schema->string()->description('Optional email theme UUID to associate'),
            'html_body' => $schema->string()->description('Raw HTML content. Stored directly as the template HTML.'),
            'mjml_body' => $schema->string()->description('Complete MJML document starting with <mjml>. Compiled server-side to cross-client HTML. Preferred over html_body.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = auth()->user()?->currentTeam;

        $data = [
            'name' => $request->get('name'),
            'subject' => $request->get('subject'),
            'preview_text' => $request->get('preview_text'),
            'status' => $request->get('status', 'draft'),
            'visibility' => $request->get('visibility', 'private'),
            'email_theme_id' => $request->get('email_theme_id'),
        ];

        $mjmlBody = $request->get('mjml_body');
        $htmlBody = $request->get('html_body');

        if ($mjmlBody !== null) {
            $data['html_cache'] = app(MjmlRenderer::class)->render($mjmlBody);
            $data['design_json'] = ['type' => 'mjml', 'source' => $mjmlBody];
        } elseif ($htmlBody !== null) {
            $data['html_cache'] = $htmlBody;
        }

        $template = app(CreateEmailTemplateAction::class)->execute($team, $data);

        return Response::text(json_encode([
            'id' => $template->id,
            'name' => $template->name,
            'status' => $template->status->value,
            'has_html_cache' => ! empty($template->html_cache),
            'message' => "Email template '{$template->name}' created. Open the builder at /email/templates/{$template->id}/edit to design it.",
        ]));
    }
}
