@props(['slugs'])

@php
$allCases = config('use_cases');
$items = collect($slugs)->map(fn($s) => isset($allCases[$s]) ? array_merge(['slug' => $s], $allCases[$s]) : null)->filter()->values();
@endphp

@if ($items->isNotEmpty())
<div
    x-data="{ shown: false }"
    x-intersect.once="shown = true"
    class="grid grid-cols-1 sm:grid-cols-3 gap-5 mt-6"
>
    @foreach ($items as $i => $case)
        <a
            href="{{ route('use-cases.show', $case['slug']) }}"
            class="group flex flex-col rounded-2xl border border-gray-200 border-t-2 {{ $case['accent'] }} bg-white p-6 shadow-sm hover:shadow-md transition-all duration-300 ease-out"
            :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
            :style="'transition-delay: {{ $i * 100 }}ms'"
        >
            <div class="flex items-center gap-3 mb-3">
                <span class="inline-flex items-center justify-center w-9 h-9 rounded-xl {{ $case['icon_bg'] }}">
                    <svg class="w-5 h-5 {{ $case['icon_text'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
                    </svg>
                </span>
                <span class="text-sm font-semibold text-gray-900">{{ $case['title_short'] }}</span>
            </div>
            <p class="text-xs text-gray-600 leading-relaxed flex-1">{{ Str::limit($case['subheading'], 100) }}</p>
            <div class="mt-4 flex items-center gap-1 text-xs font-medium {{ $case['icon_text'] }} group-hover:gap-2 transition-all">
                Explore use case
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3"/>
                </svg>
            </div>
        </a>
    @endforeach
</div>
@endif
