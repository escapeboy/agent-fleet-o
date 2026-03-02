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
                    <svg class="w-4 h-4 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>
                    </svg>
                </span>
                <span class="text-sm font-semibold text-gray-900">{{ $cap['label'] }}</span>
            </div>
            <p class="text-sm text-gray-600 leading-relaxed">{{ $cap['desc'] }}</p>
        </div>
    @endforeach
</div>
