<?php

namespace App\Mcp\Tools\Email;

use App\Domain\Email\Actions\DeleteEmailTemplateAction;
use App\Domain\Email\Models\EmailTemplate;
use App\Mcp\Attributes\AssistantTool;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class EmailTemplateDeleteTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'email_template_delete';

    protected string $description = 'Delete an email template (soft delete). The template will be marked as deleted and no longer visible.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()
                ->description('Email template UUID')
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $template = EmailTemplate::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('id'));

        if (! $template) {
            return $this->notFoundError('email template');
        }

        try {
            app(DeleteEmailTemplateAction::class)->execute($template);

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
