@props(['approval'])

@php
    $type = $approval->preview_type ?? null;
    $data = $approval->preview_data ?? [];
@endphp

@if($type && !empty($data))
    <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-4">
        <p class="mb-2 flex items-center gap-1.5 text-xs font-semibold text-amber-700 uppercase tracking-wide">
            <i class="fa-solid fa-eye fa-fw"></i>
            Preview
        </p>

        @if($type === 'code_diff')
            {{-- Unified diff viewer --}}
            <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white">
                <pre class="text-xs leading-relaxed p-3 overflow-x-auto">@foreach(explode("\n", $data['diff'] ?? '') as $line)
@php
    $cls = match(true) {
        str_starts_with($line, '+') && !str_starts_with($line, '+++') => 'bg-green-50 text-green-800',
        str_starts_with($line, '-') && !str_starts_with($line, '---') => 'bg-red-50 text-red-800',
        str_starts_with($line, '@@') => 'bg-blue-50 text-blue-700',
        default => 'text-gray-700',
    };
@endphp<span class="block {{ $cls }}">{{ $line }}</span>@endforeach</pre>
            </div>
            @if(!empty($data['file_path']))
                <p class="mt-1 text-xs text-gray-500 font-mono">{{ $data['file_path'] }}</p>
            @endif

        @elseif($type === 'email_message')
            {{-- Email preview --}}
            <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
                <div class="border-b border-gray-100 bg-gray-50 px-3 py-2 text-xs space-y-1">
                    @if(!empty($data['to']))
                        <p><span class="font-medium text-gray-500">To:</span> <span class="text-gray-700">{{ is_array($data['to']) ? implode(', ', $data['to']) : $data['to'] }}</span></p>
                    @endif
                    @if(!empty($data['subject']))
                        <p><span class="font-medium text-gray-500">Subject:</span> <span class="text-gray-700">{{ $data['subject'] }}</span></p>
                    @endif
                </div>
                <div class="max-h-48 overflow-y-auto px-3 py-3 text-sm text-gray-800 whitespace-pre-wrap">
                    {{ $data['body'] ?? '' }}
                </div>
            </div>

        @elseif($type === 'json_diff')
            {{-- JSON before/after --}}
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">Before</p>
                    <pre class="overflow-x-auto rounded-lg bg-red-50 border border-red-200 p-2 text-xs text-red-800">{{ json_encode($data['before'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
                <div>
                    <p class="mb-1 text-xs font-medium text-gray-500">After</p>
                    <pre class="overflow-x-auto rounded-lg bg-green-50 border border-green-200 p-2 text-xs text-green-800">{{ json_encode($data['after'] ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>
            </div>

        @elseif($type === 'api_request')
            {{-- HTTP request preview --}}
            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div class="border-b border-gray-100 bg-gray-50 px-3 py-2 flex items-center gap-2">
                    <span class="rounded bg-blue-100 px-1.5 py-0.5 text-xs font-semibold text-blue-700">{{ strtoupper($data['method'] ?? 'GET') }}</span>
                    <span class="text-xs font-mono text-gray-700">{{ $data['url'] ?? '' }}</span>
                </div>
                @if(!empty($data['body']))
                    <pre class="overflow-x-auto p-3 text-xs text-gray-700">{{ is_array($data['body']) ? json_encode($data['body'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : $data['body'] }}</pre>
                @endif
            </div>

        @elseif($type === 'text')
            {{-- Generic text preview --}}
            <div class="rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-800 whitespace-pre-wrap max-h-40 overflow-y-auto">
                {{ $data['content'] ?? '' }}
            </div>
        @endif
    </div>
@endif
