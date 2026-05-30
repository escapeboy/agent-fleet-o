<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Signal\Models\SignalComment;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\Concerns\ResolvesWidgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class BugReportCommentsListController extends Controller
{
    use ResolvesWidgetAccess;

    public function __invoke(Request $request, string $signal): JsonResponse
    {
        $request->validate([
            'team_public_key' => ['required', 'string'],
        ]);

        $publicKey = $request->query('team_public_key');
        $team = $this->resolveTeam($publicKey);
        $signalModel = $this->resolveBugReportSignal($team, $signal);

        $this->throttle('widget-comments-list:'.$signalModel->id, 30);

        if (! (bool) config('signals.bug_report.widget_comments_enabled', true)) {
            return $this->withCors(response()->json(['comments' => []]));
        }

        $comments = SignalComment::query()
            ->where('signal_id', $signalModel->id)
            ->where('widget_visible', true)
            ->with('media')
            ->orderBy('created_at')
            ->get()
            ->map(fn (SignalComment $c) => [
                'id' => $c->id,
                'body' => $c->body,
                'author_type' => $c->author_type,
                'created_at' => $c->created_at?->toISOString(),
                'attachments' => $c->getMedia('attachments')
                    ->map(fn (Media $m) => [
                        'url' => $this->widgetMediaUrl($signalModel, $m, $publicKey),
                        'thumb_url' => $m->hasGeneratedConversion('thumb')
                            ? $this->widgetMediaUrl($signalModel, $m, $publicKey, 'thumb')
                            : $this->widgetMediaUrl($signalModel, $m, $publicKey),
                        'mime' => $m->mime_type,
                        'size' => (int) $m->size,
                    ])
                    ->values(),
            ]);

        return $this->withCors(response()->json(['comments' => $comments]));
    }
}
