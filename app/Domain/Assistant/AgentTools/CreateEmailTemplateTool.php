<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Email\Actions\CreateEmailTemplateAction;
use App\Domain\Email\Services\MjmlRenderer;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class CreateEmailTemplateTool implements Tool
{
    public function name(): string
    {
        return 'create_email_template';
    }

    public function description(): string
    {
        return 'Create a new email template. Provide html_body (raw HTML) or mjml_body (MJML markup -- compiled server-side to HTML). After creation, visit the builder URL to refine visually.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->required()->description('Template name'),
            'subject' => $schema->string()->description('Email subject line. Supports merge tags like {{first_name}}'),
            'preview_text' => $schema->string()->description('Short inbox preview text (50-90 characters)'),
            'html_body' => $schema->string()->description('Raw HTML content for the email body'),
            'mjml_body' => $schema->string()->description('Complete MJML document (<mjml>...</mjml>). Compiled automatically to cross-client HTML. Preferred over html_body.'),
            'status' => $schema->string()->description('Status: draft, active, archived (default: draft)'),
            'visibility' => $schema->string()->description('Visibility: private, public (default: private)'),
            'email_theme_id' => $schema->string()->description('UUID of the email theme to apply'),
        ];
    }

    public function handle(Request $request): string
    {
        try {
            $team = auth()->user()->currentTeam;

            $emailThemeId = $request->get('email_theme_id');
            $emailThemeId = ($emailThemeId && Str::isUuid($emailThemeId)) ? $emailThemeId : null;

            $data = array_filter([
                'name' => $request->get('name'),
                'subject' => $request->get('subject'),
                'preview_text' => $request->get('preview_text'),
                'status' => $request->get('status', 'draft'),
                'visibility' => $request->get('visibility', 'private'),
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

            $template = app(CreateEmailTemplateAction::class)->execute($team, $data);

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
