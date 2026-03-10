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
                        <svg class="h-5 w-5 {{ $uc['icon_text'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
                        </svg>
                    </span>
                    <span class="text-sm font-semibold text-gray-900">{{ $uc['title_short'] }}</span>
                </div>
                <p class="flex-1 text-xs leading-relaxed text-gray-500">{{ Str::limit($uc['subheading'], 90) }}</p>
                <div class="mt-4 flex items-center gap-1 text-xs font-medium {{ $uc['icon_text'] }} transition-all group-hover:gap-2">
                    Explore
                    <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </div>
            </a>
        @endif
    @endforeach
</div>
