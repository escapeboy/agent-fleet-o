@props([
    'metadata' => null,
    'compact' => false,
])

@php
    /**
     * Renders an error summary chip with optional Sentry deep link.
     *
     * Reads error_metadata produced by SentryEventCapturer::capture(), which
     * is persisted by the failing record's owner (BaseStageJob::failed,
     * ExecuteCrewJob::failed, etc.).
     *
     * Usage:
     *   <x-error-badge :metadata="$stage->error_metadata" />
     *   <x-error-badge :metadata="$run->error_metadata" compact />
     */
    $payload = $metadata;
    if (is_object($payload) && method_exists($payload, 'toArray')) {
        $payload = $payload->toArray();
    }
    $payload = is_array($payload) ? $payload : null;

    $errorClass = $payload['error_class'] ?? null;
    $errorMessage = $payload['error_message'] ?? null;
    $capturedAt = $payload['captured_at'] ?? null;
    $shortClass = $errorClass ? class_basename($errorClass) : null;
@endphp

@if($payload && ($errorClass || $errorMessage))
    <div class="rounded-md border border-red-200 bg-red-50 p-3 text-sm @if(!$compact) space-y-2 @endif">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0 flex-1">
                @if($shortClass)
                    <div class="font-mono text-xs font-semibold text-red-800">{{ $shortClass }}</div>
                @endif
                @if($errorMessage)
                    <div class="@if($compact) truncate @endif text-red-900">{{ $errorMessage }}</div>
                @endif
                @if($capturedAt && !$compact)
                    <div class="text-xs text-red-600">Captured {{ \Carbon\Carbon::parse($capturedAt)->diffForHumans() }}</div>
                @endif
            </div>
            <div class="shrink-0">
                <x-sentry-link :metadata="$payload" />
            </div>
        </div>
    </div>
@endif
