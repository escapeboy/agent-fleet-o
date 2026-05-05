@php
    /** @var \App\Models\Artifact $artifact */
    /** @var string $content */
    /** @var array|null $data */
    use Illuminate\Support\Str;

    $slides = [];
    if (is_array($data) && isset($data['slides']) && is_array($data['slides'])) {
        $slides = $data['slides'];
    } else {
        $rawSections = preg_split('/^(?=#\s)/m', trim($content));
        foreach ($rawSections as $section) {
            $section = trim($section);
            if ($section === '') {
                continue;
            }
            $lines = preg_split('/\r?\n/', $section, 2);
            $title = ltrim($lines[0], '# ');
            $body = $lines[1] ?? '';
            $slides[] = ['title' => $title, 'body' => $body];
        }
    }
@endphp

<div style="background: #fef3c7; padding: 1rem 1.5rem; border-radius: 8px; margin-bottom: 1.5rem; font-size: 0.85em; color: #92400e;">
    <strong>Pitch Deck</strong> — {{ count($slides) }} {{ Str::plural('slide', count($slides)) }}
</div>

@foreach ($slides as $index => $slide)
    @php
        $title = $slide['title'] ?? 'Slide '.($index + 1);
        $body = $slide['body'] ?? ($slide['content'] ?? '');
    @endphp
    <section style="border: 1px solid #e5e7eb; border-radius: 10px; padding: 2rem; margin-bottom: 1.25rem; background: linear-gradient(180deg, #ffffff 0%, #f9fafb 100%);">
        <div style="font-size: 0.75em; color: #6b7280; letter-spacing: 0.1em; text-transform: uppercase; margin-bottom: 0.5rem;">
            Slide {{ $index + 1 }}
        </div>
        <h2 style="margin: 0 0 1rem; font-size: 1.5em; color: #111827;">{{ $title }}</h2>
        <div style="color: #374151; line-height: 1.7;">{!! Str::markdown(is_string($body) ? $body : json_encode($body, JSON_PRETTY_PRINT)) !!}</div>
    </section>
@endforeach
