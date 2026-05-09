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

    protected string $description = <<<'TXT'
Add a comment to a bug report. Agent comments are marked as author_type=agent.

Idempotency: pass `idempotency_key` to dedupe retries (e.g. `experiment:{id}:summary`,
`experiment:{id}:stage:{stage}`, `agent:{id}:run:{ai_run_id}`). With the same key on a
prior call, this is a no-op and returns the existing comment. Set `replace=true` to update
the existing comment's body in place (created_at preserved, updated_at refreshed).
Without a key, every call inserts a new comment (legacy behaviour).
TXT;

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('UUID of the bug report signal'),
            'body' => $schema->string()
                ->description('Comment text'),
            'idempotency_key' => $schema->string()
                ->description('Optional dedupe key scoped to this signal. Same key + same signal = single row.'),
            'replace' => $schema->boolean()
                ->description('When true and the key already exists, update the existing comment body in place. Default false (no-op).'),
        ];
    }

    public function handle(Request $request): Response
    {
        $signal = Signal::where('source_type', 'bug_report')->find($request->get('signal_id'));

        if (! $signal) {
            return Response::text(json_encode(['error' => 'Bug report not found']));
        }

        $idempotencyKey = $request->get('idempotency_key');
        $replace = (bool) $request->get('replace', false);

        $comment = app(AddSignalCommentAction::class)->execute(
            signal: $signal,
            body: $request->get('body'),
            authorType: 'agent',
            idempotencyKey: $idempotencyKey !== null && $idempotencyKey !== '' ? (string) $idempotencyKey : null,
            replace: $replace,
        );

        return Response::text(json_encode([
            'comment_id' => $comment->id,
            'body' => $comment->body,
            'idempotency_key' => $comment->idempotency_key,
            'created_at' => $comment->created_at?->toISOString(),
            'updated_at' => $comment->updated_at?->toISOString(),
            'deduped' => $idempotencyKey !== null && ! $comment->wasRecentlyCreated,
        ]));
    }
}
