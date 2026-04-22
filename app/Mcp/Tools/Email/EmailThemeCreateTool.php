<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Actions\CreateEmailThemeAction;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class EmailThemeCreateTool extends Tool
{
    protected string $name = 'email_theme_create';

    protected string $description = 'Create a new email theme for the current team. All color and layout fields are optional and have sensible defaults (blue primary, Inter font, 600px width).';

    public function schema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('Theme name')->required(),
            'primary_color' => $schema->string()->description('Primary/CTA color as hex (e.g. #2563eb). Default: #2563eb'),
            'background_color' => $schema->string()->description('Email background color as hex. Default: #f4f4f4'),
            'canvas_color' => $schema->string()->description('Content area background color as hex. Default: #ffffff'),
            'text_color' => $schema->string()->description('Body text color as hex. Default: #1f2937'),
            'heading_color' => $schema->string()->description('Heading text color as hex. Default: #111827'),
            'font_name' => $schema->string()->description('Font display name (e.g. Inter, Georgia). Default: Inter'),
            'font_url' => $schema->string()->description('Google Fonts or web font URL for @import'),
            'font_family' => $schema->string()->description('Full CSS font-family stack. Default: Inter, Arial, sans-serif'),
            'logo_url' => $schema->string()->description('Absolute URL to the team logo image'),
            'email_width' => $schema->integer()->description('Maximum email width in pixels. Default: 600'),
            'company_name' => $schema->string()->description('Company name shown in email footer'),
            'company_address' => $schema->string()->description('Company address shown in email footer'),
            'footer_text' => $schema->string()->description('Footer text or HTML (e.g. unsubscribe line)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $team = auth()->user()?->currentTeam;

        try {
            $theme = app(CreateEmailThemeAction::class)->execute($team, array_filter([
                'name' => $request->get('name'),
                'primary_color' => $request->get('primary_color'),
                'background_color' => $request->get('background_color'),
                'canvas_color' => $request->get('canvas_color'),
                'text_color' => $request->get('text_color'),
                'heading_color' => $request->get('heading_color'),
                'font_name' => $request->get('font_name'),
                'font_url' => $request->get('font_url'),
                'font_family' => $request->get('font_family'),
                'logo_url' => $request->get('logo_url'),
                'email_width' => $request->get('email_width'),
                'company_name' => $request->get('company_name'),
                'company_address' => $request->get('company_address'),
                'footer_text' => $request->get('footer_text'),
            ], fn ($v) => $v !== null));

            return Response::text(json_encode([
                'success' => true,
                'id' => $theme->id,
                'name' => $theme->name,
                'status' => $theme->status->value,
                'primary_color' => $theme->primary_color,
                'font_name' => $theme->font_name,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
