<?php

namespace App\Mcp\Tools\Signal;

use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Enums\SignalStatus;
use App\Domain\Signal\Models\Signal;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
#[AssistantTool('destructive')]
class BugReportConfirmResolutionTool extends Tool
{
    protected string $name = 'bug_report_confirm_resolution';

    protected string $description = 'Record a reporter decision on a resolved bug report. Pass confirmed=true to keep it resolved or confirmed=false to reopen it back to received. Adds a reporter-authored comment either way.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'signal_id' => $schema->string()
                ->description('UUID of the bug report signal. Must be in status=resolved.'),
            'confirmed' => $schema->boolean()
                ->description('True if the reporter confirms the fix; false to reject and reopen the signal.'),
            'comment' => $schema->string()
                ->description('Optional reporter note appended to the audit comment.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $signal = Signal::query()
            ->where('source_type', 'bug_report')
            ->find($request->get('signal_id'));

        if (! $signal) {
            return Response::text(json_encode(['error' => 'Bug report not found']));
        }

        $currentStatus = $signal->status instanceof SignalStatus
            ? $signal->status
            : SignalStatus::tryFrom((string) $signal->status);

        if ($currentStatus !== SignalStatus::Resolved) {
            return Response::text(json_encode([
                'error' => 'Bug report must be in status=resolved to accept a confirmation decision.',
                'current_status' => $currentStatus?->value,
            ]));
        }

        $confirmed = (bool) $request->get('confirmed');
        $note = trim((string) $request->get('comment', ''));

        $result = DB::transaction(function () use ($signal, $confirmed, $note) {
            $body = $confirmed
                ? trim('Reporter confirmed fix. '.$note)
                : trim('Reporter rejected fix — reopening. '.$note);

            if (! $confirmed) {
                $signal->forceFill(['status' => SignalStatus::Received->value])->save();
            }

            $comment = app(AddSignalCommentAction::class)->execute(
                signal: $signal,
                body: $body,
                authorType: CommentAuthorType::Reporter,
            );

            return [
                'status' => $signal->fresh()->status,
                'comment_id' => $comment->id,
            ];
        });

        $status = $result['status'];
        if ($status instanceof SignalStatus) {
            $status = $status->value;
        }

        return Response::text(json_encode([
            'signal_id' => $signal->id,
            'status' => $status,
            'confirmed' => $confirmed,
            'comment_id' => $result['comment_id'],
        ]));
    }
}
