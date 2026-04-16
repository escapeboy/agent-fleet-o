@php
    /** @var \App\Models\Artifact $artifact */
    /** @var string $content */
    /** @var array|null $data */
    use Illuminate\Support\Str;

    $touches = [];
    if (is_array($data)) {
        $touches = $data['touches'] ?? $data['emails'] ?? $data['sequence'] ?? [];
    }
@endphp

<div style="background: #f0f9ff; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85em; color: #075985;">
    <strong>Sales Sequence</strong> — {{ count($touches) }} {{ Str::plural('touch', count($touches)) }}
</div>

@if (empty($touches))
    <div>{!! Str::markdown($content) !!}</div>
@else
    @foreach ($touches as $index => $touch)
        <article style="border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 1rem; background: #fff; overflow: hidden;">
            <header style="background: #f9fafb; padding: 0.75rem 1.25rem; border-bottom: 1px solid #e5e7eb; display: flex; justify-content: space-between; align-items: center; font-size: 0.85em;">
                <span><strong>Touch {{ $index + 1 }}</strong> &middot; {{ $touch['channel'] ?? 'email' }}</span>
                <span style="color: #6b7280;">{{ $touch['day'] ?? $touch['wait'] ?? 'Day '.($index * 3 + 1) }}</span>
            </header>
            <div style="padding: 1rem 1.25rem;">
                @if (isset($touch['subject']))
                    <div style="font-size: 0.8em; color: #6b7280; margin-bottom: 0.3rem;">Subject</div>
                    <div style="font-weight: 600; margin-bottom: 0.75rem;">{{ $touch['subject'] }}</div>
                @endif
                <div style="font-size: 0.8em; color: #6b7280; margin-bottom: 0.3rem;">Body</div>
                <div style="white-space: pre-wrap; color: #374151;">{{ $touch['body'] ?? $touch['copy'] ?? '' }}</div>
                @if (isset($touch['cta']))
                    <div style="margin-top: 1rem; padding-top: 0.75rem; border-top: 1px dashed #e5e7eb; font-size: 0.85em;">
                        <strong>CTA:</strong> {{ $touch['cta'] }}
                    </div>
                @endif
            </div>
        </article>
    @endforeach
@endif
