<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Actions\GenerateEmailTemplateAction;
use App\Domain\Email\Models\EmailTheme;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class EmailTemplateGenerateTool extends Tool
{
    protected string $name = 'email_template_generate';

    protected string $description = 'Generate MJML email markup from a natural language description using AI. Returns mjml_source and html_preview without saving to the database. Use email_template_create or email_template_update to persist the result after review.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'description' => $schema->string()
                ->description('Natural language description of the email to generate, e.g. "Monthly newsletter with hero image, 3 product highlights, and a CTA button"')
                ->required(),
            'theme_id' => $schema->string()
                ->description('Optional email theme UUID. Brand colors, fonts, company name, and logo from the theme will be injected into the generated MJML.'),
            'tone' => $schema->string()
                ->description('Writing tone: professional, friendly, or minimal (default: professional)')
                ->enum(['professional', 'friendly', 'minimal']),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = auth()->user()?->currentTeam;

        $theme = null;
        $themeId = $request->get('theme_id');
        if ($themeId) {
            $theme = EmailTheme::find($themeId);
            if (! $theme) {
                return Response::error("Email theme '{$themeId}' not found.");
            }
        }

        try {
            $result = app(GenerateEmailTemplateAction::class)->execute(
                description: $request->get('description'),
                theme: $theme,
                tone: $request->get('tone', 'professional'),
                teamId: $team?->id,
            );

            $mjmlLength = strlen($result['mjml_source']);
            $htmlLength = strlen($result['html_preview']);
            $compiled = $result['html_preview'] !== $result['mjml_source'];

            return Response::text(json_encode([
                'mjml_source' => $result['mjml_source'],
                'html_preview' => $result['html_preview'],
                'subject_suggestion' => $result['subject_suggestion'],
                'mjml_compiled' => $compiled,
                'mjml_length' => $mjmlLength,
                'html_length' => $htmlLength,
                'next_steps' => 'Review the output, then call email_template_create with mjml_body to save it, or email_template_update to overwrite an existing template.',
            ]));
        } catch (\Throwable $e) {
            return Response::error('Email generation failed: '.$e->getMessage());
        }
    }
}
