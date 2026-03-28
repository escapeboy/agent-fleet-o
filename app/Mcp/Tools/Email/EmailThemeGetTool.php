<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Models\EmailTheme;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class EmailThemeGetTool extends Tool
{
    protected string $name = 'email_theme_get';

    protected string $description = 'Get full details of a specific email theme by ID.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Email theme UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $theme = EmailTheme::withoutGlobalScopes()->where('team_id', $teamId)->findOrFail($request->get('id'));

        return Response::text(json_encode([
            'id' => $theme->id,
            'name' => $theme->name,
            'status' => $theme->status->value,
            'logo_url' => $theme->logo_url,
            'logo_width' => $theme->logo_width,
            'background_color' => $theme->background_color,
            'canvas_color' => $theme->canvas_color,
            'primary_color' => $theme->primary_color,
            'text_color' => $theme->text_color,
            'heading_color' => $theme->heading_color,
            'muted_color' => $theme->muted_color,
            'divider_color' => $theme->divider_color,
            'font_name' => $theme->font_name,
            'font_url' => $theme->font_url,
            'font_family' => $theme->font_family,
            'heading_font_size' => $theme->heading_font_size,
            'body_font_size' => $theme->body_font_size,
            'line_height' => $theme->line_height,
            'email_width' => $theme->email_width,
            'content_padding' => $theme->content_padding,
            'company_name' => $theme->company_name,
            'company_address' => $theme->company_address,
            'footer_text' => $theme->footer_text,
            'created_at' => $theme->created_at?->toIso8601String(),
            'updated_at' => $theme->updated_at?->toIso8601String(),
        ]));
    }
}
