<?php

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Models\SignalComment;
use Illuminate\Support\Facades\Log;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Spatie\MediaLibrary\MediaCollections\Models\Media;
use Throwable;

/**
 * Re-encodes user-uploaded images via Intervention before attaching them
 * to a SignalComment's 'attachments' media collection. GD re-encode on
 * save() naturally strips EXIF/geo-metadata — reused by both the widget
 * comment endpoint and the Livewire admin composer.
 */
class CommentAttachmentIngester
{
    /**
     * @param  SignalComment  $comment  Already persisted comment.
     * @param  string  $sourcePath  Real filesystem path of the uploaded image.
     * @param  string  $originalFileName  Original client-supplied name (for sanitization).
     * @return Media|null Attached Media or null when the upload failed sanitization.
     */
    public function attachReencodedImage(
        SignalComment $comment,
        string $sourcePath,
        string $originalFileName,
    ): ?Media {
        $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        $normalizedExt = $extension === 'jpeg' ? 'jpg' : $extension;

        if (! in_array($normalizedExt, ['jpg', 'png', 'webp', 'gif'], true)) {
            return null;
        }

        $tmpPath = tempnam(sys_get_temp_dir(), 'fleetq_img_').'.'.$normalizedExt;

        try {
            $manager = new ImageManager(new Driver);
            $image = $manager->decodePath($sourcePath);
            // save() encodes using the path's extension; GD re-encodes on write
            // which strips EXIF/metadata from the stored copy.
            $image->save($tmpPath);

            return $comment->addMedia($tmpPath)
                ->usingFileName($this->safeFileName($originalFileName, $normalizedExt))
                ->toMediaCollection('attachments');
        } catch (Throwable $e) {
            @unlink($tmpPath);
            Log::warning('comment attachment re-encode failed', [
                'comment_id' => $comment->id,
                'signal_id' => $comment->signal_id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function safeFileName(string $originalName, string $extension): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $slug = preg_replace('/[^A-Za-z0-9_-]+/', '-', $base);
        $slug = trim((string) $slug, '-') ?: 'attachment';

        return mb_substr($slug, 0, 80).'.'.$extension;
    }
}
