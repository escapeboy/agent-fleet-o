<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Signal\Models\SignalComment;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\Concerns\ResolvesWidgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BugReportCommentsListController extends Controller
{
    use ResolvesWidgetAccess;

    public function __invoke(Request $request, string $signal): JsonResponse
    {
        $request->validate([
            'team_public_key' => ['required', 'string'],
        ]);

        $team = $this->resolveTeam($request->query('team_public_key'));
        $signalModel = $this->resolveBugReportSignal($team, $signal);

        $this->throttle('widget-comments-list:'.$signalModel->id, 30);

        if (! (bool) config('signals.bug_report.widget_comments_enabled', true)) {
            return $this->withCors(response()->json(['comments' => []]));
        }

        $comments = SignalComment::query()
            ->where('signal_id', $signalModel->id)
            ->where('widget_visible', true)
            ->orderBy('created_at')
            ->get(['id', 'body', 'author_type', 'created_at'])
            ->map(fn (SignalComment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'author_type' => $c->author_type,
                'created_at' => $c->created_at?->toISOString(),
            ]);

        return $this->withCors(response()->json(['comments' => $comments]));
    }
}
