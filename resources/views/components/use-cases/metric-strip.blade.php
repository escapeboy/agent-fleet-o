@props(['metrics'])

<div
    x-data="{ shown: false }"
    x-intersect.once="shown = true"
    class="grid grid-cols-1 sm:grid-cols-3 gap-6 my-12"
>
    @foreach ($metrics as $i => $metric)
        <div
            class="text-center p-6 rounded-2xl bg-white border border-gray-200 shadow-sm transition duration-500 ease-out"
            :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
            :style="'transition-delay: {{ $i * 100 }}ms'"
        >
            <div class="text-4xl font-extrabold tracking-tight bg-gradient-to-r from-primary-600 to-violet-600 bg-clip-text text-transparent mb-1">
                {{ $metric['number'] }}
            </div>
            <div class="text-sm font-semibold text-gray-900 mb-1">{{ $metric['label'] }}</div>
            <div class="text-xs text-gray-500 leading-relaxed">{{ $metric['description'] }}</div>
        </div>
    @endforeach
</div>
