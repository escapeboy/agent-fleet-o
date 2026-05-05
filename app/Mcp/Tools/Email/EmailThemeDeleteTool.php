<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Actions\DeleteEmailThemeAction;
use App\Domain\Email\Models\EmailTheme;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class EmailThemeDeleteTool extends Tool
{
    use HasStructuredErrors;

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
            return $this->permissionDeniedError('No current team.');
        }
        $theme = EmailTheme::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('id'));

        if (! $theme) {
            return $this->notFoundError('email theme');
        }

        try {
            app(DeleteEmailThemeAction::class)->execute($theme);

            return Response::text(json_encode([
                'success' => true,
                'id' => $request->get('id'),
                'deleted' => true,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }
}
