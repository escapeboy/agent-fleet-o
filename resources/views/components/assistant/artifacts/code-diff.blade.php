@props(['payload' => [], 'open' => false])

@php
    $title = $payload['title'] ?? 'Code change';
    $language = $payload['language'] ?? 'text';
    $filePath = $payload['file_path'] ?? '';
    $before = $payload['before'] ?? '';
    $after = $payload['after'] ?? '';
@endphp

<x-assistant.artifacts.wrapper type="code_diff" :title="$title" icon="code" :open="$open">
    @if($filePath)
        <p class="mb-2 font-mono text-[10px] text-gray-500">{{ $filePath }} · {{ $language }}</p>
    @endif

    <div class="grid gap-2 text-[11px]" aria-label="Code diff for {{ $title }}">
        <div>
            <p class="mb-0.5 font-medium text-red-600">− Before</p>
            <pre class="overflow-x-auto rounded border border-red-200 bg-red-50 p-2 font-mono text-[10px] leading-4 text-red-900"><code>{{ $before }}</code></pre>
        </div>
        <div>
            <p class="mb-0.5 font-medium text-emerald-600">+ After</p>
            <pre class="overflow-x-auto rounded border border-emerald-200 bg-emerald-50 p-2 font-mono text-[10px] leading-4 text-emerald-900"><code>{{ $after }}</code></pre>
        </div>
    </div>
</x-assistant.artifacts.wrapper>
