@props(['steps'])

@php
$colorMap = [
    'blue'    => ['bg' => 'bg-blue-100',    'border' => 'border-blue-300',    'text' => 'text-blue-700',    'dot' => 'bg-blue-500',    'ring' => 'ring-blue-200'],
    'primary' => ['bg' => 'bg-primary-50',  'border' => 'border-primary-300', 'text' => 'text-primary-700', 'dot' => 'bg-primary-500', 'ring' => 'ring-primary-200'],
    'violet'  => ['bg' => 'bg-violet-100',  'border' => 'border-violet-300',  'text' => 'text-violet-700',  'dot' => 'bg-violet-500',  'ring' => 'ring-violet-200'],
    'amber'   => ['bg' => 'bg-amber-50',    'border' => 'border-amber-300',   'text' => 'text-amber-700',   'dot' => 'bg-amber-500',   'ring' => 'ring-amber-200'],
    'green'   => ['bg' => 'bg-green-50',    'border' => 'border-green-300',   'text' => 'text-green-700',   'dot' => 'bg-green-500',   'ring' => 'ring-green-200'],
];

$typeIcons = [
    'signal'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/>',
    'agent'    => '<path stroke-linecap="round" stroke-linejoin="round" d="M9.813 15.904L9 18.75l-.813-2.846a4.5 4.5 0 00-3.09-3.09L2.25 12l2.846-.813a4.5 4.5 0 003.09-3.09L9 5.25l.813 2.846a4.5 4.5 0 003.09 3.09L15.75 12l-2.846.813a4.5 4.5 0 00-3.09 3.09z"/>',
    'approval' => '<path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275"/>',
    'output'   => '<path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
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
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            {!! $typeIcons[$step['type']] ?? $typeIcons['agent'] !!}
                        </svg>
                    </span>
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center justify-between gap-2">
                            <span class="text-sm font-semibold {{ $c['text'] }}">{{ $step['label'] }}</span>
                            <svg
                                class="w-4 h-4 flex-shrink-0 {{ $c['text'] }} transition-transform duration-200"
                                :class="active === {{ $i }} ? 'rotate-180' : ''"
                                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"
                            >
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
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
                        <svg class="w-5 h-5 {{ $c['text'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            {!! $typeIcons[$step['type']] ?? $typeIcons['agent'] !!}
                        </svg>
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
                    <svg class="w-3 h-3 text-gray-400 -ml-0.5 flex-shrink-0" fill="currentColor" viewBox="0 0 8 8">
                        <path d="M0 0l8 4-8 4V0z"/>
                    </svg>
                </div>
            @endif
        @endforeach
    </div>

    {{-- Step count hint --}}
    <p class="mt-6 text-center text-xs text-gray-400 sm:block hidden">
        Click any step to learn more &nbsp;·&nbsp; {{ count($steps) }} steps
    </p>
</div>
