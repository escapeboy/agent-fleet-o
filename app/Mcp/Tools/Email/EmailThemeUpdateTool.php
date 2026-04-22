<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Actions\UpdateEmailThemeAction;
use App\Domain\Email\Models\EmailTheme;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class EmailThemeUpdateTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'email_theme_update';

    protected string $description = 'Update an existing email theme. Only supply fields you want to change — omitted fields are preserved.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Email theme UUID')->required(),
            'name' => $schema->string()->description('Theme name'),
            'status' => $schema->string()
                ->description('Status: draft, active, archived')
                ->enum(['draft', 'active', 'archived']),
            'primary_color' => $schema->string()->description('Primary/CTA color as hex (e.g. #2563eb)'),
            'background_color' => $schema->string()->description('Email background color as hex'),
            'canvas_color' => $schema->string()->description('Content area background color as hex'),
            'text_color' => $schema->string()->description('Body text color as hex'),
            'heading_color' => $schema->string()->description('Heading text color as hex'),
            'muted_color' => $schema->string()->description('Muted/secondary text color as hex'),
            'divider_color' => $schema->string()->description('Divider/border color as hex'),
            'font_name' => $schema->string()->description('Font display name (e.g. Inter, Georgia)'),
            'font_url' => $schema->string()->description('Google Fonts or web font URL for @import'),
            'font_family' => $schema->string()->description('Full CSS font-family stack'),
            'heading_font_size' => $schema->integer()->description('Heading font size in pixels'),
            'body_font_size' => $schema->integer()->description('Body font size in pixels'),
            'logo_url' => $schema->string()->description('Absolute URL to the team logo image'),
            'logo_width' => $schema->integer()->description('Logo width in pixels'),
            'email_width' => $schema->integer()->description('Maximum email width in pixels'),
            'content_padding' => $schema->integer()->description('Content area horizontal padding in pixels'),
            'company_name' => $schema->string()->description('Company name shown in email footer'),
            'company_address' => $schema->string()->description('Company address shown in email footer'),
            'footer_text' => $schema->string()->description('Footer text or HTML'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $theme = EmailTheme::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('id'));

        if (! $theme) {
            return $this->notFoundError('email theme');
        }

        try {
            $data = array_filter([
                'name' => $request->get('name'),
                'status' => $request->get('status'),
                'primary_color' => $request->get('primary_color'),
                'background_color' => $request->get('background_color'),
                'canvas_color' => $request->get('canvas_color'),
                'text_color' => $request->get('text_color'),
                'heading_color' => $request->get('heading_color'),
                'muted_color' => $request->get('muted_color'),
                'divider_color' => $request->get('divider_color'),
                'font_name' => $request->get('font_name'),
                'font_url' => $request->get('font_url'),
                'font_family' => $request->get('font_family'),
                'heading_font_size' => $request->get('heading_font_size'),
                'body_font_size' => $request->get('body_font_size'),
                'logo_url' => $request->get('logo_url'),
                'logo_width' => $request->get('logo_width'),
                'email_width' => $request->get('email_width'),
                'content_padding' => $request->get('content_padding'),
                'company_name' => $request->get('company_name'),
                'company_address' => $request->get('company_address'),
                'footer_text' => $request->get('footer_text'),
            ], fn ($v) => $v !== null);

            $theme = app(UpdateEmailThemeAction::class)->execute($theme, $data);

            return Response::text(json_encode([
                'success' => true,
                'id' => $theme->id,
                'name' => $theme->name,
                'status' => $theme->status->value,
                'primary_color' => $theme->primary_color,
                'font_name' => $theme->font_name,
                'updated_at' => $theme->updated_at?->toIso8601String(),
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
