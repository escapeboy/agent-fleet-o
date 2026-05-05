@php
    /** @var \App\Models\Artifact $artifact */
    /** @var string $content */
    /** @var array|null $data */
    use Illuminate\Support\Str;

    $tam = is_array($data) ? ($data['tam'] ?? null) : null;
    $sam = is_array($data) ? ($data['sam'] ?? null) : null;
    $som = is_array($data) ? ($data['som'] ?? null) : null;
    $competitors = is_array($data) && isset($data['competitors']) && is_array($data['competitors'])
        ? $data['competitors']
        : [];
    $summary = is_array($data) ? ($data['summary'] ?? null) : null;
@endphp

<div style="background: #f5f3ff; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85em; color: #5b21b6;">
    <strong>Market Research</strong>
</div>

@if ($tam || $sam || $som)
    <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; margin-bottom: 1.5rem;">
        @foreach (['TAM' => $tam, 'SAM' => $sam, 'SOM' => $som] as $label => $value)
            <div style="border: 1px solid #e5e7eb; border-radius: 10px; padding: 1.25rem; background: #fff; text-align: center;">
                <div style="font-size: 0.75em; text-transform: uppercase; letter-spacing: 0.1em; color: #6b7280;">{{ $label }}</div>
                <div style="font-size: 1.4em; font-weight: 600; margin-top: 0.5rem; color: #111827;">{{ $value ?? '—' }}</div>
            </div>
        @endforeach
    </div>
@endif

@if ($summary)
    <div style="background: #f9fafb; padding: 1rem 1.25rem; border-radius: 8px; margin-bottom: 1.5rem;">
        <div style="font-size: 0.75em; text-transform: uppercase; letter-spacing: 0.1em; color: #6b7280; margin-bottom: 0.5rem;">Summary</div>
        <div>{!! Str::markdown($summary) !!}</div>
    </div>
@endif

@if (!empty($competitors))
    <h2>Competitors</h2>
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Positioning</th>
                <th>Strengths</th>
                <th>Weaknesses</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($competitors as $c)
                <tr>
                    <td><strong>{{ $c['name'] ?? '—' }}</strong></td>
                    <td>{{ $c['positioning'] ?? '' }}</td>
                    <td style="color: #065f46; font-size: 0.9em;">{{ is_array($c['strengths'] ?? null) ? implode(', ', $c['strengths']) : ($c['strengths'] ?? '') }}</td>
                    <td style="color: #991b1b; font-size: 0.9em;">{{ is_array($c['weaknesses'] ?? null) ? implode(', ', $c['weaknesses']) : ($c['weaknesses'] ?? '') }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif

@if (!$tam && !$sam && !$som && empty($competitors) && !$summary)
    <div>{!! Str::markdown($content) !!}</div>
@endif
