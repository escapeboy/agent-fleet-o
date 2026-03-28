<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Models\EmailTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
class EmailTemplateGetTool extends Tool
{
    protected string $name = 'email_template_get';

    protected string $description = 'Get full details of a specific email template by ID, including rendered HTML cache.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('Email template UUID')->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $template = EmailTemplate::withoutGlobalScopes()->where('team_id', $teamId)->findOrFail($request->get('id'));

        return Response::text(json_encode([
            'id' => $template->id,
            'name' => $template->name,
            'subject' => $template->subject,
            'preview_text' => $template->preview_text,
            'status' => $template->status->value,
            'visibility' => $template->visibility->value,
            'email_theme_id' => $template->email_theme_id,
            'has_html_cache' => ! empty($template->html_cache),
            'html_cache' => $template->html_cache,
            'created_at' => $template->created_at?->toIso8601String(),
            'updated_at' => $template->updated_at?->toIso8601String(),
        ]));
    }
}
