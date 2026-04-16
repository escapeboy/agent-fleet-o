<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\Concerns\ResolvesWidgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BugReportCommentsCreateController extends Controller
{
    use ResolvesWidgetAccess;

    public function __invoke(Request $request, string $signal, AddSignalCommentAction $action): JsonResponse
    {
        $validated = $request->validate([
            'team_public_key' => ['required', 'string'],
            'body' => ['required', 'string', 'max:10000'],
            'reporter_name' => ['nullable', 'string', 'max:255'],
        ]);

        $team = $this->resolveTeam($validated['team_public_key']);
        $signalModel = $this->resolveBugReportSignal($team, $signal);

        $this->throttle('widget-comments-create:'.$signalModel->id, 10);

        if (! (bool) config('signals.bug_report.widget_comments_enabled', true)) {
            return $this->withCors(response()->json(['error' => 'comments_disabled'], 403));
        }

        $body = $this->sanitizeBody($validated['body']);

        if ($body === '') {
            return $this->withCors(response()->json(['error' => 'empty_body'], 422));
        }

        $comment = $action->execute(
            signal: $signalModel,
            body: $body,
            authorType: CommentAuthorType::Reporter,
        );

        return $this->withCors(response()->json([
            'comment_id' => $comment->id,
            'body' => $comment->body,
            'author_type' => $comment->author_type,
            'created_at' => $comment->created_at?->toISOString(),
        ], 201));
    }
}
