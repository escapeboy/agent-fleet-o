@php
    /** @var \App\Models\Artifact $artifact */
    /** @var string $content */
    /** @var array|null $data */

    $rows = [];
    $headers = [];

    if (is_array($data) && isset($data['rows']) && is_array($data['rows'])) {
        $rows = $data['rows'];
        $headers = $data['headers'] ?? (isset($rows[0]) && is_array($rows[0]) ? array_keys($rows[0]) : []);
    } elseif (str_contains($content, ',')) {
        $lines = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $content))));
        if ($lines !== []) {
            $headers = str_getcsv(array_shift($lines));
            foreach ($lines as $line) {
                $values = str_getcsv($line);
                $rows[] = array_combine($headers, array_pad($values, count($headers), ''));
            }
        }
    }

    $summary = is_array($data) ? ($data['summary'] ?? null) : null;
@endphp

<div style="background: #ecfdf5; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85em; color: #065f46;">
    <strong>Financial Model</strong> — {{ count($rows) }} {{ \Illuminate\Support\Str::plural('row', count($rows)) }}
</div>

@if ($summary)
    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.5rem;">
        @foreach ($summary as $label => $value)
            <div style="border: 1px solid #e5e7eb; border-radius: 8px; padding: 1rem; background: #fff;">
                <div style="font-size: 0.75em; text-transform: uppercase; letter-spacing: 0.1em; color: #6b7280;">{{ $label }}</div>
                <div style="font-size: 1.3em; font-weight: 600; margin-top: 0.3rem; color: #111827;">{{ is_numeric($value) ? number_format((float) $value, 2) : $value }}</div>
            </div>
        @endforeach
    </div>
@endif

@if (empty($rows))
    <pre style="background: #f3f4f6; padding: 1rem; border-radius: 8px; overflow-x: auto;">{{ $content }}</pre>
@else
    <table>
        <thead>
            <tr>
                @foreach ($headers as $h)
                    <th>{{ $h }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($rows as $row)
                <tr>
                    @foreach ($headers as $h)
                        <td style="font-family: 'SF Mono', monospace; font-size: 0.85em;">
                            {{ is_numeric($row[$h] ?? null) ? number_format((float) $row[$h], 2) : ($row[$h] ?? '') }}
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
