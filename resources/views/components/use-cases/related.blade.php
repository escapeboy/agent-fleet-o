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
                    <i class="fa-solid fa-wand-magic-sparkles text-lg {{ $case['icon_text'] }}"></i>
                </span>
                <span class="text-sm font-semibold text-gray-900">{{ $case['title_short'] }}</span>
            </div>
            <p class="text-xs text-gray-600 leading-relaxed flex-1">{{ Str::limit($case['subheading'], 100) }}</p>
            <div class="mt-4 flex items-center gap-1 text-xs font-medium {{ $case['icon_text'] }} group-hover:gap-2 transition-all">
                Explore use case
                <i class="fa-solid fa-arrow-right text-xs"></i>
            </div>
        </a>
    @endforeach
</div>
@endif
