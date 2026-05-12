<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry\Sentry;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\Facades\Log;
use Sentry\State\HubInterface;
use Throwable;

/**
 * Single chokepoint for capturing exceptions to Sentry.
 *
 * Responsibilities:
 *   1. Apply FleetQ context to the active Sentry scope.
 *   2. Compute and apply a fingerprint for grouping.
 *   3. Call Sentry's captureException() through the configured Hub.
 *   4. Return a CapturedEvent DTO so callers can persist {event_id, error_class,
 *      error_message, captured_at} on the failing record.
 *   5. Fire `ErrorCaptured` event so Prometheus listeners (Sprint 3) can
 *      increment counters without coupling to the registry directly.
 *
 * Graceful when Sentry DSN is empty: returns a CapturedEvent with eventId=null,
 * never throws.
 *
 * This class replaces every `\Sentry\captureException()` call site in app code.
 * A CI grep gate (see scripts/check-sentry-direct-usage.sh) blocks new code from
 * regressing to the direct call.
 */
final class SentryEventCapturer
{
    public function __construct(
        private readonly SentryContext $context,
        private readonly FingerprintResolver $fingerprinter,
        private readonly HubInterface $hub,
        private readonly Dispatcher $events,
    ) {}

    /**
     * Capture an exception with FleetQ context. Returns a CapturedEvent
     * containing the Sentry event_id (or null if Sentry is disabled).
     *
     * @param  array<string, mixed>  $options  Supported keys:
     *     - context: array of FleetQ context fields (see SentryContext::TAG_KEYS)
     *     - fingerprint: explicit override (skips FingerprintResolver)
     */
    public function capture(Throwable $exception, array $options = []): CapturedEvent
    {
        $context = is_array($options['context'] ?? null) ? $options['context'] : [];
        $explicitFingerprint = is_array($options['fingerprint'] ?? null) ? $options['fingerprint'] : null;

        $fingerprint = $explicitFingerprint ?? $this->fingerprinter->resolve($exception, $context);

        $eventId = $this->safeCapture($exception, $context, $fingerprint);

        $captured = new CapturedEvent(
            eventId: $eventId,
            errorClass: $exception::class,
            errorMessage: $exception->getMessage(),
            capturedAt: CarbonImmutable::now(),
            tags: $this->context->tagsFor($context),
            fingerprint: $fingerprint,
        );

        // Fire event for listeners (Prometheus counter, audit log, etc.).
        // Wrapped so a misbehaving listener can't poison the capture path.
        try {
            $this->events->dispatch(new ErrorCaptured($captured, $exception, $context));
        } catch (Throwable $e) {
            Log::warning('SentryEventCapturer: ErrorCaptured listener failed', [
                'error' => $e->getMessage(),
            ]);
        }

        return $captured;
    }

    /**
     * Wraps the actual Sentry SDK call in a try/catch and a scope.
     *
     * Sentry's PHP SDK is reentrant-safe but throws if the transport is
     * misconfigured — we never want the observability layer to break a user
     * request, so all failures are swallowed and logged.
     *
     * @param  array<string, mixed>  $context
     * @param  array<int, string>  $fingerprint
     */
    private function safeCapture(Throwable $exception, array $context, array $fingerprint): ?string
    {
        try {
            $eventId = null;
            $this->hub->withScope(function ($scope) use ($exception, $context, $fingerprint, &$eventId): void {
                $this->context->apply($scope, $context);
                if ($fingerprint !== []) {
                    $scope->setFingerprint($fingerprint);
                }
                $captured = $this->hub->captureException($exception);
                $eventId = $captured?->__toString();
            });

            return $eventId;
        } catch (Throwable $e) {
            Log::warning('SentryEventCapturer: Sentry capture failed', [
                'error' => $e->getMessage(),
                'original_exception_class' => $exception::class,
            ]);

            return null;
        }
    }
}
