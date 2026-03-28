<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Actions\DeleteEmailThemeAction;
use App\Domain\Email\Models\EmailTheme;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class EmailThemeDeleteTool extends Tool
{
    protected string $name = 'email_theme_delete';

    protected string $description = 'Delete an email theme (soft delete). The theme will be marked as deleted.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Email theme UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }
        $theme = EmailTheme::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('id'));

        if (! $theme) {
            return Response::error('Email theme not found.');
        }

        try {
            app(DeleteEmailThemeAction::class)->execute($theme);

            return Response::text(json_encode([
                'success' => true,
                'id' => $request->get('id'),
                'deleted' => true,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }
}
