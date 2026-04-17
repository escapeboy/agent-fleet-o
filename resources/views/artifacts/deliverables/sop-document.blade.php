@php
    /** @var \App\Models\Artifact $artifact */
    /** @var string $content */
    /** @var array|null $data */
    use Illuminate\Support\Str;

    $title = is_array($data) ? ($data['title'] ?? $artifact->name) : $artifact->name;
    $purpose = is_array($data) ? ($data['purpose'] ?? null) : null;
    $owner = is_array($data) ? ($data['owner'] ?? null) : null;
    $steps = [];
    if (is_array($data) && isset($data['steps']) && is_array($data['steps'])) {
        $steps = $data['steps'];
    }
@endphp

<div style="background: #fef2f2; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85em; color: #991b1b;">
    <strong>SOP Document</strong>
    @if ($owner) &middot; Owner: <strong>{{ $owner }}</strong> @endif
</div>

<h1>{{ $title }}</h1>

@if ($purpose)
    <div style="background: #f9fafb; padding: 1rem 1.25rem; border-left: 3px solid #6366f1; border-radius: 4px; margin-bottom: 1.5rem;">
        <strong>Purpose:</strong> {{ $purpose }}
    </div>
@endif

@if (empty($steps))
    <div>{!! Str::markdown($content) !!}</div>
@else
    <ol style="padding-left: 0; list-style: none;">
        @foreach ($steps as $index => $step)
            <li style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 1.25rem 1.5rem; margin-bottom: 0.75rem; background: #fff;">
                <div style="display: flex; align-items: baseline; gap: 0.75rem; margin-bottom: 0.5rem;">
                    <span style="display: inline-flex; align-items: center; justify-content: center; width: 28px; height: 28px; background: #6366f1; color: #fff; border-radius: 50%; font-weight: 600; font-size: 0.85em;">
                        {{ $index + 1 }}
                    </span>
                    <strong style="font-size: 1.05em;">{{ $step['title'] ?? $step['name'] ?? 'Step '.($index + 1) }}</strong>
                </div>
                @if (isset($step['role']))
                    <div style="font-size: 0.8em; color: #6b7280; margin-bottom: 0.5rem;">Role: <strong>{{ $step['role'] }}</strong></div>
                @endif
                <div style="color: #374151;">{{ $step['description'] ?? $step['detail'] ?? '' }}</div>
            </li>
        @endforeach
    </ol>
@endif
