<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('write')]
class BugReportAddCommentTool extends Tool
{
    protected string $name = 'bug_report_add_comment';

    protected string $description = 'Add a comment to a bug report. Agent comments are marked as author_type=agent.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('UUID of the bug report signal'),
            'body' => $schema->string()
                ->description('Comment text'),
        ];
    }

    public function handle(Request $request): Response
    {
        $signal = Signal::where('source_type', 'bug_report')->find($request->get('signal_id'));

        if (! $signal) {
            return Response::text(json_encode(['error' => 'Bug report not found']));
        }

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $signal,
            body: $request->get('body'),
            authorType: 'agent',
        );

        return Response::text(json_encode([
            'comment_id' => $comment->id,
            'body' => $comment->body,
            'created_at' => $comment->created_at?->toISOString(),
        ]));
    }
}
