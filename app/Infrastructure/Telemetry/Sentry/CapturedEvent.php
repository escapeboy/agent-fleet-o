<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry\Sentry;

use Carbon\CarbonImmutable;
use Throwable;

/**
 * Immutable record of a single capture attempt against Sentry.
 *
 * Returned by SentryEventCapturer::capture(); persisted by the caller into the
 * failing record's `error_metadata` JSONB column so the admin UI can render a
 * deep link back to the Sentry issue without re-querying the SDK.
 */
final class CapturedEvent
{
    public function __construct(
        public readonly ?string $eventId,
        public readonly string $errorClass,
        public readonly string $errorMessage,
        public readonly CarbonImmutable $capturedAt,
        public readonly array $tags,
        public readonly array $fingerprint,
    ) {}

    /**
     * Build the persistable metadata array for storing on a failing record.
     *
     * @return array<string, mixed>
     */
    public function toMetadata(Throwable $exception): array
    {
        return [
            'sentry_event_id' => $this->eventId,
            'error_class' => $this->errorClass,
            'error_message' => $this->truncatedMessage($this->errorMessage),
            'captured_at' => $this->capturedAt->toIso8601String(),
            'tags' => $this->tags,
            'fingerprint' => $this->fingerprint,
            'trace' => $this->shortTrace($exception),
        ];
    }

    /**
     * Truncates message at 1000 characters to keep JSONB rows small. Errors with
     * mega-stack-dumps still get captured fully in Sentry — we only store the
     * pointer.
     */
    private function truncatedMessage(string $message): string
    {
        if (mb_strlen($message) <= 1000) {
            return $message;
        }

        return mb_substr($message, 0, 1000)."\u{2026}";
    }

    /**
     * Short trace preview: file + line of the throwing frame. Skips vendor frames.
     */
    private function shortTrace(Throwable $exception): array
    {
        $file = $exception->getFile();
        $line = $exception->getLine();

        // If the throw originated in vendor, walk the trace to find first non-vendor frame.
        if (str_contains($file, '/vendor/')) {
            foreach ($exception->getTrace() as $frame) {
                $candidateFile = $frame['file'] ?? null;
                if ($candidateFile === null || str_contains($candidateFile, '/vendor/')) {
                    continue;
                }
                $file = $candidateFile;
                $line = $frame['line'] ?? 0;
                break;
            }
        }

        return [
            'file' => $this->stripBasePath($file),
            'line' => $line,
        ];
    }

    private function stripBasePath(string $path): string
    {
        $base = base_path();
        if (str_starts_with($path, $base)) {
            return ltrim(substr($path, strlen($base)), '/');
        }

        return $path;
    }
}
