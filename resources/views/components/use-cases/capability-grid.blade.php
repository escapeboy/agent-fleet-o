@props(['capabilities'])

<div
    x-data="{ shown: false }"
    x-intersect.once="shown = true"
    class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5 mt-8"
>
    @foreach ($capabilities as $i => $cap)
        <div
            class="rounded-2xl border border-gray-200 border-t-2 border-t-primary-400 bg-white p-6 shadow-sm transition duration-500 ease-out hover:shadow-md"
            :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
            :style="'transition-delay: {{ $i * 80 }}ms'"
        >
            <div class="flex items-center gap-3 mb-2">
                <span class="inline-flex items-center justify-center w-7 h-7 rounded-lg bg-primary-50">
                    <i class="fa-solid fa-wand-magic-sparkles text-base text-primary-600"></i>
                </span>
                <span class="text-sm font-semibold text-gray-900">{{ $cap['label'] }}</span>
            </div>
            <p class="text-sm text-gray-600 leading-relaxed">{{ $cap['desc'] }}</p>
        </div>
    @endforeach
</div>
