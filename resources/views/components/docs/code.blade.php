@props(['lang' => 'bash', 'title' => null])

<div class="my-5 overflow-hidden rounded-xl border border-gray-200">
    @if ($title)
        <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-xs font-medium text-gray-500">
            {{ $title }}
        </div>
    @else
        <div class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-xs font-medium text-gray-400 uppercase tracking-wide">
            {{ $lang }}
        </div>
    @endif
    <div class="overflow-x-auto bg-gray-950 p-4">
        <pre class="text-sm leading-relaxed text-gray-100"><code>{{ $slot }}</code></pre>
    </div>
</div>
