@props(['slugs' => []])

@php
$allUseCases = config('use_cases', []);
@endphp

<div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
    @foreach($slugs as $i => $slug)
        @php $uc = $allUseCases[$slug] ?? null; @endphp
        @if($uc)
            <a href="{{ route('use-cases.show', $slug) }}"
               class="group flex flex-col rounded-2xl border border-gray-200 border-t-2 {{ $uc['accent'] }} bg-white p-6 shadow-sm transition hover:shadow-md"
               x-data="{ shown: false }"
               x-intersect.once="shown = true"
               :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
               class="transition duration-500 ease-out"
               style="transition-delay: {{ $i * 100 }}ms">
                <div class="flex items-center gap-3 mb-3">
                    <span class="inline-flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg {{ $uc['icon_bg'] }}">
                        <i class="fa-solid fa-wand-magic-sparkles text-lg {{ $uc['icon_text'] }}"></i>
                    </span>
                    <span class="text-sm font-semibold text-gray-900">{{ $uc['title_short'] }}</span>
                </div>
                <p class="flex-1 text-xs leading-relaxed text-gray-500">{{ Str::limit($uc['subheading'], 90) }}</p>
                <div class="mt-4 flex items-center gap-1 text-xs font-medium {{ $uc['icon_text'] }} transition-all group-hover:gap-2">
                    Explore
                    <i class="fa-solid fa-chevron-right text-xs"></i>
                </div>
            </a>
        @endif
    @endforeach
</div>
