@props(['faqs'])

<div
    x-data="{ active: null, shown: false }"
    x-intersect.once="shown = true"
    class="mt-4"
>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-x-12 gap-y-3">
        @foreach ($faqs as $i => $faq)
            <div
                class="rounded-xl border border-gray-200 bg-white overflow-hidden transition duration-500 ease-out"
                :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                :style="'transition-delay: {{ $i * 60 }}ms'"
            >
                <button
                    type="button"
                    class="w-full flex items-center justify-between gap-4 px-5 py-4 text-left hover:bg-gray-50 transition-colors"
                    @click="active = active === {{ $i }} ? null : {{ $i }}"
                >
                    <span class="text-sm font-semibold text-gray-900 leading-snug">{{ $faq['question'] }}</span>
                    <svg
                        class="w-4 h-4 flex-shrink-0 text-gray-400 transition-transform duration-200"
                        :class="active === {{ $i }} ? 'rotate-180 text-primary-600' : ''"
                        fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                    >
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                    </svg>
                </button>
                <div x-show="active === {{ $i }}" x-collapse>
                    <p class="px-5 pb-4 text-sm text-gray-600 leading-relaxed border-t border-gray-100 pt-3">
                        {{ $faq['answer'] }}
                    </p>
                </div>
            </div>
        @endforeach
    </div>
</div>
