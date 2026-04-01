<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Email\Actions\UpdateEmailTemplateAction;
use App\Domain\Email\Models\EmailTemplate;
use App\Domain\Email\Services\MjmlRenderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class UpdateEmailTemplateTool implements Tool
{
    public function name(): string
    {
        return 'update_email_template';
    }

    public function description(): string
    {
        return 'Update an existing email template metadata or body content. Provide html_body or mjml_body to set HTML content. Only supply fields you want to change -- omitted fields are preserved.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()->required()->description('Email template UUID'),
            'name' => $schema->string()->description('Template name'),
            'subject' => $schema->string()->description('Email subject line. Supports merge tags like {{first_name}}'),
            'preview_text' => $schema->string()->description('Short inbox preview text (50-90 characters)'),
            'html_body' => $schema->string()->description('Raw HTML content for the email body'),
            'mjml_body' => $schema->string()->description('Complete MJML document (<mjml>...</mjml>). Compiled automatically to cross-client HTML. Preferred over html_body.'),
            'status' => $schema->string()->description('Status: draft, active, archived'),
            'visibility' => $schema->string()->description('Visibility: private, public'),
            'email_theme_id' => $schema->string()->description('UUID of the email theme to apply'),
        ];
    }

    public function handle(Request $request): string
    {
        $template = EmailTemplate::find($request->get('template_id'));
        if (! $template) {
            return json_encode(['error' => 'Email template not found']);
        }

        try {
            $emailThemeId = $request->get('email_theme_id');
            $emailThemeId = ($emailThemeId && Str::isUuid($emailThemeId)) ? $emailThemeId : null;

            $data = array_filter([
                'name' => $request->get('name'),
                'subject' => $request->get('subject'),
                'preview_text' => $request->get('preview_text'),
                'status' => $request->get('status'),
                'visibility' => $request->get('visibility'),
                'email_theme_id' => $emailThemeId,
            ], fn ($v) => $v !== null);

            $mjmlBody = $request->get('mjml_body');
            $htmlBody = $request->get('html_body');

            if ($mjmlBody !== null) {
                $data['html_cache'] = app(MjmlRenderer::class)->render($mjmlBody);
                $data['design_json'] = ['type' => 'mjml', 'source' => $mjmlBody];
            } elseif ($htmlBody !== null) {
                $data['html_cache'] = $htmlBody;
            }

            $template = app(UpdateEmailTemplateAction::class)->execute($template, $data);

            return json_encode([
                'success' => true,
                'template_id' => $template->id,
                'name' => $template->name,
                'status' => $template->status->value,
                'has_html_cache' => ! empty($template->html_cache),
                'url' => route('email.templates.edit', $template),
            ]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
