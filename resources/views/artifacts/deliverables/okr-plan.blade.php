@php
    /** @var \App\Models\Artifact $artifact */
    /** @var string $content */
    /** @var array|null $data */
    use Illuminate\Support\Str;

    $quarter = is_array($data) ? ($data['quarter'] ?? $data['period'] ?? null) : null;
    $objectives = is_array($data) && isset($data['objectives']) && is_array($data['objectives'])
        ? $data['objectives']
        : [];
@endphp

<div style="background: #fffbeb; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85em; color: #92400e;">
    <strong>OKR Plan</strong>
    @if ($quarter) &middot; {{ $quarter }} @endif
    &middot; {{ count($objectives) }} {{ Str::plural('objective', count($objectives)) }}
</div>

@if (empty($objectives))
    <div>{!! Str::markdown($content) !!}</div>
@else
    @foreach ($objectives as $objIndex => $obj)
        <section style="border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.5rem; margin-bottom: 1rem; background: #fff;">
            <div style="display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 1rem;">
                <h2 style="margin: 0; font-size: 1.2em;">O{{ $objIndex + 1 }}: {{ $obj['objective'] ?? $obj['title'] ?? '' }}</h2>
                @if (isset($obj['owner']))
                    <span style="font-size: 0.85em; color: #6b7280;">Owner: <strong>{{ $obj['owner'] }}</strong></span>
                @endif
            </div>
            @if (isset($obj['description']))
                <p style="margin-top: 0; color: #4b5563;">{{ $obj['description'] }}</p>
            @endif
            @if (isset($obj['key_results']) && is_array($obj['key_results']))
                <div style="font-size: 0.75em; text-transform: uppercase; letter-spacing: 0.1em; color: #6b7280; margin: 1rem 0 0.5rem;">Key Results</div>
                <ul style="list-style: none; padding-left: 0; margin: 0;">
                    @foreach ($obj['key_results'] as $krIndex => $kr)
                        <li style="padding: 0.75rem 1rem; background: #f9fafb; border-radius: 6px; margin-bottom: 0.4rem; display: flex; justify-content: space-between; align-items: center;">
                            <span><strong style="color: #6366f1;">KR{{ $krIndex + 1 }}.</strong> {{ is_array($kr) ? ($kr['text'] ?? $kr['description'] ?? '') : $kr }}</span>
                            @if (is_array($kr) && isset($kr['target']))
                                <span style="font-family: 'SF Mono', monospace; font-size: 0.85em; color: #065f46;">{{ $kr['target'] }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endforeach
@endif
