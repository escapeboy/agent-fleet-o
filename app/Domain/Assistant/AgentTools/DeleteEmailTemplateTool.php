<?php

namespace App\Domain\Assistant\AgentTools;

use App\Domain\Email\Actions\DeleteEmailTemplateAction;
use App\Domain\Email\Models\EmailTemplate;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class DeleteEmailTemplateTool implements Tool
{
    public function name(): string
    {
        return 'delete_email_template';
    }

    public function description(): string
    {
        return 'Delete an email template (soft delete). This is a destructive action -- the template will be permanently removed from the list.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'template_id' => $schema->string()->required()->description('Email template UUID'),
        ];
    }

    public function handle(Request $request): string
    {
        $template = EmailTemplate::find($request->get('template_id'));
        if (! $template) {
            return json_encode(['error' => 'Email template not found']);
        }

        try {
            $name = $template->name;
            app(DeleteEmailTemplateAction::class)->execute($template);

            return json_encode(['success' => true, 'message' => "Email template '{$name}' deleted."]);
        } catch (\Throwable $e) {
            return json_encode(['error' => $e->getMessage()]);
        }
    }
}
