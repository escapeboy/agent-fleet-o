<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Signal\Models\Signal;
use App\Domain\Signal\Models\SignalComment;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\Concerns\ResolvesWidgetAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams bug-report widget media (comment attachments / original files) on the
 * public, unauthenticated widget channel. Access is scoped by the same
 * widget_public_key + signal model as the other widget endpoints, plus an
 * ownership check that the media belongs to a widget-visible comment (or the
 * signal itself). Streams from the media's own disk, so it works whether media
 * lives on the local disk or a private S3 bucket.
 */
class BugReportMediaController extends Controller
{
    use ResolvesWidgetAccess;

    /** Conversions a widget visitor may request — never arbitrary. */
    private const ALLOWED_CONVERSIONS = ['thumb'];

    public function __invoke(Request $request, string $signal, string $media): StreamedResponse
    {
        $request->validate([
            'team_public_key' => ['required', 'string'],
            'conversion' => ['nullable', 'string'],
        ]);

        $team = $this->resolveTeam($request->query('team_public_key'));
        $signalModel = $this->resolveBugReportSignal($team, $signal);

        $this->throttle('widget-media:'.$signalModel->id, 120);

        /** @var Media|null $mediaModel */
        $mediaModel = Media::query()->find($media);

        abort_unless($mediaModel !== null && $this->belongsToSignal($mediaModel, $signalModel), 404);

        $conversion = $request->query('conversion');
        if ($conversion !== null && ! in_array($conversion, self::ALLOWED_CONVERSIONS, true)) {
            abort(404);
        }

        $path = $mediaModel->getPathRelativeToRoot($conversion ?? '');
        $disk = Storage::disk($mediaModel->disk);

        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, [
            'Access-Control-Allow-Origin' => '*',
            'Access-Control-Allow-Methods' => 'GET, OPTIONS',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    private function belongsToSignal(Media $media, Signal $signal): bool
    {
        $model = $media->model;

        if ($model instanceof Signal) {
            return $model->id === $signal->id;
        }

        if ($model instanceof SignalComment) {
            return $model->signal_id === $signal->id && (bool) $model->widget_visible;
        }

        return false;
    }
}
