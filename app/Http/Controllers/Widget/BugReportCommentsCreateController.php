<?php

namespace App\Http\Controllers\Widget;

use App\Domain\Signal\Actions\AddSignalCommentAction;
use App\Domain\Signal\Enums\CommentAuthorType;
use App\Domain\Signal\Models\SignalComment;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Widget\Concerns\ResolvesWidgetAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

class BugReportCommentsCreateController extends Controller
{
    use ResolvesWidgetAccess;

    public function __invoke(Request $request, string $signal, AddSignalCommentAction $action): JsonResponse
    {
        $maxAttachments = (int) config('signals.bug_report.widget_comment_max_attachments', 4);
        $maxMb = (int) config('signals.bug_report.widget_comment_max_attachment_mb', 5);
        $attachmentsEnabled = (bool) config('signals.bug_report.widget_comment_attachments_enabled', true);

        $validated = $request->validate([
            'team_public_key' => ['required', 'string'],
            'body' => ['nullable', 'string', 'max:10000'],
            'reporter_name' => ['nullable', 'string', 'max:255'],
            'images' => ['nullable', 'array', 'max:'.$maxAttachments],
            'images.*' => [
                'file',
                'image',
                'mimes:jpg,jpeg,png,webp,gif',
                'max:'.($maxMb * 1024),
            ],
        ]);

        $team = $this->resolveTeam($validated['team_public_key']);
        $signalModel = $this->resolveBugReportSignal($team, $signal);

        $images = array_values(array_filter($request->file('images', []) ?? []));

        if ($images !== [] && ! $attachmentsEnabled) {
            return $this->withCors(response()->json(['error' => 'attachments_disabled'], 422));
        }

        // Separate bucket for multipart — image re-encoding + storage is heavier
        // than plain text and should be rate-limited independently.
        if ($images !== []) {
            $this->throttle('widget-comments-create-media:'.$signalModel->id, 5);
        }
        $this->throttle('widget-comments-create:'.$signalModel->id, 10);

        if (! (bool) config('signals.bug_report.widget_comments_enabled', true)) {
            return $this->withCors(response()->json(['error' => 'comments_disabled'], 403));
        }

        $body = $this->sanitizeBody($validated['body'] ?? '');

        if ($body === '' && $images === []) {
            return $this->withCors(response()->json(['error' => 'empty_comment'], 422));
        }

        $comment = $action->execute(
            signal: $signalModel,
            body: $body,
            authorType: CommentAuthorType::Reporter,
        );

        foreach ($images as $file) {
            $this->attachReencodedImage($comment, $file);
        }

        return $this->withCors(response()->json([
            'comment_id' => $comment->id,
            'body' => $comment->body,
            'author_type' => $comment->author_type,
            'created_at' => $comment->created_at?->toISOString(),
            'attachments' => $this->serializeAttachments($comment->fresh()->getMedia('attachments')),
        ], 201));
    }

    /**
     * Re-encode the uploaded image via Intervention to strip EXIF and any
     * non-image payloads, then hand the clean copy to Media Library.
     */
    private function attachReencodedImage(SignalComment $comment, UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $normalizedExt = $extension === 'jpeg' ? 'jpg' : $extension;

        $tmpPath = tempnam(sys_get_temp_dir(), 'fleetq_img_').'.'.$normalizedExt;

        try {
            $manager = new ImageManager(new Driver());
            $image = $manager->decodePath($file->getRealPath());
            // save() encodes using the path's extension; GD re-encodes on write
            // which strips EXIF/metadata from the stored copy.
            $image->save($tmpPath);

            $comment->addMedia($tmpPath)
                ->usingFileName($this->safeFileName($file->getClientOriginalName(), $normalizedExt))
                ->toMediaCollection('attachments');
        } catch (Throwable $e) {
            // Re-encode failures (decompression bomb, malformed file) are swallowed —
            // the comment body still persists; caller sees fewer attachments back.
            @unlink($tmpPath);
            Log::warning('widget comment attachment re-encode failed', [
                'comment_id' => $comment->id,
                'signal_id' => $comment->signal_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function safeFileName(string $originalName, string $extension): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);
        $slug = trim((string) $slug, '-') ?: 'attachment';

        return mb_substr($slug, 0, 80).'.'.$extension;
    }

    /**
     * @param  iterable<Media>  $media
     * @return array<int, array{url: string, thumb_url: string, mime: ?string, size: int}>
     */
    private function serializeAttachments(iterable $media): array
    {
        $out = [];
        foreach ($media as $m) {
            $full = $m->getFullUrl();
            $thumb = $m->hasGeneratedConversion('thumb') ? $m->getFullUrl('thumb') : $full;
            $out[] = [
                'url' => $full,
                'thumb_url' => $thumb,
                'mime' => $m->mime_type,
                'size' => (int) $m->size,
            ];
        }

        return $out;
    }
}
