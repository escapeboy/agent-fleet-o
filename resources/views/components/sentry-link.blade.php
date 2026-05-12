@props([
    'metadata' => null,
    'label' => 'View in Sentry',
    'class' => '',
])

@php
    /**
     * Deep-link to a Sentry issue / event composed from a persisted
     * error_metadata payload. Renders nothing when SENTRY_ORG_SLUG is empty
     * or when no metadata is available (graceful degradation).
     *
     * Usage:
     *   <x-sentry-link :metadata="$stage->error_metadata" />
     *   <x-sentry-link :metadata="$run->error_metadata" label="Open in Sentry →" />
     */
    $url = $metadata
        ? app(\App\Infrastructure\Telemetry\Sentry\SentryUrlBuilder::class)->fromMetadata(
            is_array($metadata) ? $metadata : $metadata->toArray()
        )
        : null;
@endphp

@if($url)
    <a
        href="{{ $url }}"
        target="_blank"
        rel="noopener noreferrer"
        class="inline-flex items-center gap-1.5 text-sm font-medium text-red-700 hover:text-red-900 hover:underline {{ $class }}"
        title="Open the captured error in Sentry"
    >
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-4">
            <path d="M10 1.5a8.5 8.5 0 1 0 0 17 8.5 8.5 0 0 0 0-17ZM6.5 13l-1-2 4.5-8 4.5 8-1 2H6.5Z" />
        </svg>
        <span>{{ $label }}</span>
        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" class="size-3.5">
            <path d="M5.22 14.78a.75.75 0 0 1 0-1.06l8.97-8.97H7.75a.75.75 0 0 1 0-1.5h7.5a.75.75 0 0 1 .75.75v7.5a.75.75 0 0 1-1.5 0V5.81l-8.97 8.97a.75.75 0 0 1-1.06 0Z" />
        </svg>
    </a>
@endif
