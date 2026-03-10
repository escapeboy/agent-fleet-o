@props(['faqs' => []])

<div x-data="{ active: null }" class="divide-y divide-gray-200">
    @foreach($faqs as $i => $item)
        <div class="py-5"
             x-data="{ shown: false }"
             x-intersect.once="shown = true"
             :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
             class="transition duration-400 ease-out"
             style="transition-delay: {{ $i * 60 }}ms">
            <button id="uc-faq-q{{ $i }}"
                    @click="active = active === {{ $i }} ? null : {{ $i }}"
                    :aria-expanded="active === {{ $i }}"
                    aria-controls="uc-faq-a{{ $i }}"
                    class="flex w-full items-center justify-between gap-4 rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">
                <span class="text-base font-medium text-gray-900">{{ $item['question'] }}</span>
                <svg :class="active === {{ $i }} && 'rotate-180'"
                     class="ml-4 h-5 w-5 flex-shrink-0 text-gray-400 transition-transform duration-200"
                     fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                </svg>
            </button>
            <div id="uc-faq-a{{ $i }}"
                 x-show="active === {{ $i }}"
                 x-cloak
                 x-collapse
                 role="region"
                 aria-labelledby="uc-faq-q{{ $i }}"
                 class="mt-3 text-sm leading-relaxed text-gray-600">
                {{ $item['answer'] }}
            </div>
        </div>
    @endforeach
</div>
