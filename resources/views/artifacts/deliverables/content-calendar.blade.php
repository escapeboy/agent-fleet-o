@php
    /** @var \App\Models\Artifact $artifact */
    /** @var string $content */
    /** @var array|null $data */

    $entries = [];
    if (is_array($data)) {
        $entries = $data['entries'] ?? ($data[0] ?? null) !== null && is_array($data[0]) ? $data : [];
        if (isset($data['entries'])) {
            $entries = $data['entries'];
        }
    }
@endphp

<div style="background: #eef2ff; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85em; color: #3730a3;">
    <strong>Content Calendar</strong> — {{ count($entries) }} scheduled {{ \Illuminate\Support\Str::plural('entry', count($entries)) }}
</div>

@if (empty($entries))
    <pre style="background: #f3f4f6; padding: 1rem; border-radius: 8px; overflow-x: auto;">{{ $content }}</pre>
@else
    <table>
        <thead>
            <tr>
                <th style="width: 110px;">Date</th>
                <th style="width: 120px;">Platform</th>
                <th>Post</th>
                <th style="width: 120px;">Hook / CTA</th>
            </tr>
        </thead>
        <tbody>
            @foreach ($entries as $entry)
                <tr>
                    <td style="white-space: nowrap; font-family: 'SF Mono', monospace; font-size: 0.85em;">{{ $entry['date'] ?? $entry['when'] ?? '—' }}</td>
                    <td>{{ $entry['platform'] ?? $entry['channel'] ?? '—' }}</td>
                    <td>
                        @if (isset($entry['title']))
                            <strong>{{ $entry['title'] }}</strong><br>
                        @endif
                        {{ $entry['copy'] ?? $entry['body'] ?? '' }}
                    </td>
                    <td style="font-size: 0.85em; color: #4b5563;">{{ $entry['cta'] ?? $entry['hook'] ?? '' }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
