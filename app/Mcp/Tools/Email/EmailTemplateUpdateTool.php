<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Actions\UpdateEmailTemplateAction;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Services\MjmlRenderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

class EmailTemplateUpdateTool extends Tool
{
    protected string $name = 'email_template_update';

    protected string $description = 'Update an existing email template. Provide html_body (raw HTML) or mjml_body (MJML markup — compiled server-side to HTML automatically). Only supply fields you want to change — omitted fields are preserved.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Email template UUID')
                ->required(),
            'name' => $schema->string()->description('Template name'),
            'subject' => $schema->string()->description('Email subject line. Supports merge tags like {{first_name}}'),
            'preview_text' => $schema->string()->description('Short preview text shown in email client inbox (50–90 characters recommended)'),
            'status' => $schema->string()
                ->description('Status: draft, active, archived')
                ->enum(['draft', 'active', 'archived']),
            'visibility' => $schema->string()
                ->description('Visibility: private, public')
                ->enum(['private', 'public']),
            'email_theme_id' => $schema->string()->description('Email theme UUID to associate (set to null to detach)'),
            'html_body' => $schema->string()->description('Raw HTML content. Stored directly as html_cache.'),
            'mjml_body' => $schema->string()->description('Complete MJML document starting with <mjml>. Compiled server-side to cross-client HTML. Preferred over html_body when MJML infrastructure is available.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $template = EmailTemplate::find($request->get('id'));

        if (! $template) {
            return Response::error('Email template not found.');
        }

        try {
            $data = array_filter([
                'name' => $request->get('name'),
                'subject' => $request->get('subject'),
                'preview_text' => $request->get('preview_text'),
                'status' => $request->get('status'),
                'visibility' => $request->get('visibility'),
                'email_theme_id' => $request->get('email_theme_id'),
            ], fn ($v) => $v !== null);

            $mjmlCompiled = false;
            $mjmlBody = $request->get('mjml_body');
            $htmlBody = $request->get('html_body');

            if ($mjmlBody !== null) {
                $compiled = app(MjmlRenderer::class)->render($mjmlBody);
                $data['html_cache'] = $compiled;
                $data['design_json'] = ['type' => 'mjml', 'source' => $mjmlBody];
                $mjmlCompiled = true;
            } elseif ($htmlBody !== null) {
                $data['html_cache'] = $htmlBody;
            }

            $template = app(UpdateEmailTemplateAction::class)->execute($template, $data);

            return Response::text(json_encode([
                'success' => true,
                'id' => $template->id,
                'name' => $template->name,
                'status' => $template->status->value,
                'has_html_cache' => ! empty($template->html_cache),
                'mjml_compiled' => $mjmlCompiled,
                'updated_at' => $template->updated_at?->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
