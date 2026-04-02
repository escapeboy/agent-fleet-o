@props(['steps'])

@php
$colorMap = [
    'blue'    => ['bg' => 'bg-blue-100',    'border' => 'border-blue-300',    'text' => 'text-blue-700',    'dot' => 'bg-blue-500',    'ring' => 'ring-blue-200'],
    'primary' => ['bg' => 'bg-primary-50',  'border' => 'border-primary-300', 'text' => 'text-primary-700', 'dot' => 'bg-primary-500', 'ring' => 'ring-primary-200'],
    'violet'  => ['bg' => 'bg-violet-100',  'border' => 'border-violet-300',  'text' => 'text-violet-700',  'dot' => 'bg-violet-500',  'ring' => 'ring-violet-200'],
    'amber'   => ['bg' => 'bg-amber-50',    'border' => 'border-amber-300',   'text' => 'text-amber-700',   'dot' => 'bg-amber-500',   'ring' => 'ring-amber-200'],
    'green'   => ['bg' => 'bg-green-50',    'border' => 'border-green-300',   'text' => 'text-green-700',   'dot' => 'bg-green-500',   'ring' => 'ring-green-200'],
];

@endphp

<div
    x-data="{ active: null, revealed: false }"
    x-intersect.once="revealed = true"
    class="relative mt-8 mb-2"
>
    {{-- Mobile: vertical stack --}}
    <div class="flex flex-col gap-0 sm:hidden">
        @foreach ($steps as $i => $step)
            @php $c = $colorMap[$step['color']] ?? $colorMap['primary']; @endphp
            <div
                class="transition duration-500 ease-out"
                :class="revealed ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                :style="'transition-delay: {{ $i * 80 }}ms'"
            >
                <button
                    type="button"
                    class="w-full flex items-start gap-4 p-4 text-left rounded-xl {{ $c['bg'] }} border {{ $c['border'] }} hover:shadow-sm transition-shadow"
                    @click="active = active === {{ $i }} ? null : {{ $i }}"
                >
                    <span class="flex-shrink-0 mt-0.5 flex items-center justify-center w-8 h-8 rounded-full {{ $c['dot'] }} text-white">
                        @switch($step['type'])
                            @case('signal') <i class="fa-solid fa-bolt text-base"></i> @break
                            @case('agent') <i class="fa-solid fa-wand-magic-sparkles text-base"></i> @break
                            @case('approval') <i class="fa-solid fa-users text-base"></i> @break
                            @case('output') <i class="fa-solid fa-circle-check text-base"></i> @break
                            @default <i class="fa-solid fa-wand-magic-sparkles text-base"></i>
                        @endswitch
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold {{ $c['text'] }}">{{ $step['label'] }}</span>
                            <i
                                class="fa-solid fa-chevron-down text-base flex-shrink-0 {{ $c['text'] }} transition-transform duration-200"
                                :class="active === {{ $i }} ? 'rotate-180' : ''"
                            ></i>
                        </div>
                        <p class="text-xs text-gray-600 mt-1 leading-relaxed" x-show="active === {{ $i }}" x-collapse>
                            {{ $step['description'] }}
                        </p>
                    </div>
                </button>
                @if (!$loop->last)
                    <div class="flex justify-center py-1">
                        <div class="w-0.5 h-5 bg-gray-200"></div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Desktop: horizontal flow --}}
    <div class="hidden sm:flex items-start gap-0">
        @foreach ($steps as $i => $step)
            @php $c = $colorMap[$step['color']] ?? $colorMap['primary']; @endphp

            {{-- Step node --}}
            <div
                class="flex flex-col items-center flex-1 min-w-0 transition duration-500 ease-out"
                :class="revealed ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'"
                :style="'transition-delay: {{ $i * 100 }}ms'"
            >
                {{-- Icon bubble --}}
                <button
                    type="button"
                    class="group relative flex flex-col items-center w-full cursor-pointer"
                    @click="active = active === {{ $i }} ? null : {{ $i }}"
                >
                    <span
                        class="flex items-center justify-center w-12 h-12 rounded-full border-2 {{ $c['border'] }} {{ $c['bg'] }} ring-4 {{ $c['ring'] }} ring-opacity-0 transition-all duration-200 group-hover:ring-opacity-100"
                        :class="active === {{ $i }} ? '{{ $c['ring'] }} ring-opacity-100 scale-110' : ''"
                    >
                        @switch($step['type'])
                            @case('signal') <i class="fa-solid fa-bolt text-lg {{ $c['text'] }}"></i> @break
                            @case('agent') <i class="fa-solid fa-wand-magic-sparkles text-lg {{ $c['text'] }}"></i> @break
                            @case('approval') <i class="fa-solid fa-users text-lg {{ $c['text'] }}"></i> @break
                            @case('output') <i class="fa-solid fa-circle-check text-lg {{ $c['text'] }}"></i> @break
                            @default <i class="fa-solid fa-wand-magic-sparkles text-lg {{ $c['text'] }}"></i>
                        @endswitch
                    </span>
                    <span class="mt-2.5 text-xs font-semibold {{ $c['text'] }} text-center leading-tight px-1">{{ $step['label'] }}</span>
                </button>

                {{-- Tooltip card --}}
                <div
                    x-show="active === {{ $i }}"
                    x-transition:enter="transition ease-out duration-200"
                    x-transition:enter-start="opacity-0 scale-95 -translate-y-1"
                    x-transition:enter-end="opacity-100 scale-100 translate-y-0"
                    x-transition:leave="transition ease-in duration-150"
                    x-transition:leave-start="opacity-100 scale-100 translate-y-0"
                    x-transition:leave-end="opacity-0 scale-95 -translate-y-1"
                    class="absolute z-20 mt-1 w-56 rounded-xl shadow-lg border {{ $c['border'] }} {{ $c['bg'] }} p-3 text-xs text-gray-700 leading-relaxed"
                    style="top: 4.5rem; left: 50%; transform: translateX(-50%)"
                    @click.outside="active = null"
                >
                    <p class="font-semibold {{ $c['text'] }} mb-1">{{ $step['label'] }}</p>
                    {{ $step['description'] }}
                </div>
            </div>

            {{-- Arrow between steps --}}
            @if (!$loop->last)
                <div
                    class="flex-shrink-0 flex items-center pt-4 transition duration-500 ease-out"
                    :class="revealed ? 'opacity-100' : 'opacity-0'"
                    :style="'transition-delay: {{ ($i * 100) + 60 }}ms'"
                >
                    <div class="h-px w-6 bg-gray-300"></div>
                    <i class="fa-solid fa-chevron-right text-xs text-gray-400 -ml-0.5 flex-shrink-0"></i>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Step count hint --}}
    <p class="mt-6 text-center text-xs text-gray-400 sm:block hidden">
        Click any step to learn more &nbsp;·&nbsp; {{ count($steps) }} steps
    </p>
</div>
