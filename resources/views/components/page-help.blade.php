@php
    $routeName = Route::currentRouteName();
    $help = config("page-help.{$routeName}");
@endphp

@if($help)
<div x-data="{ open: false }"
     @toggle-page-help.window="open = !open"
     @keydown.escape.window="if (open) { open = false }">

    <div x-show="open"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         x-cloak
         role="region"
         aria-label="Page help: {{ $help['title'] }}"
         class="mb-5 rounded-xl border border-(--color-theme-border) bg-(--color-surface-alt) p-5">

        {{-- Header --}}
        <div class="mb-3 flex items-start justify-between">
            <div>
                <h3 class="text-sm font-semibold text-(--color-on-surface)">{{ $help['title'] }}</h3>
                <p class="mt-1 text-sm text-(--color-on-surface-muted)">{{ $help['description'] }}</p>
            </div>
            <button @click="open = false"
                    class="ml-4 shrink-0 rounded-md p-1 text-(--color-on-surface-muted) hover:text-(--color-on-surface)"
                    aria-label="Close help">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </button>
        </div>

        <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
            {{-- Quick Start Steps --}}
            @if(!empty($help['steps']))
            <div>
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-(--color-theme-primary)">Quick Start</h4>
                <ol class="space-y-1.5 text-sm text-(--color-on-surface-muted)">
                    @foreach($help['steps'] as $step)
                        <li class="flex gap-2">
                            <span class="mt-0.5 flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-(--color-surface-raised) text-[10px] font-bold text-(--color-theme-primary) ring-1 ring-inset ring-(--color-theme-border)">{{ $loop->iteration }}</span>
                            <span>{{ $step }}</span>
                        </li>
                    @endforeach
                </ol>
            </div>
            @endif

            {{-- Tips --}}
            @if(!empty($help['tips']))
            <div>
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-(--color-theme-primary)">Tips</h4>
                <ul class="space-y-1.5 text-sm text-(--color-on-surface-muted)">
                    @foreach($help['tips'] as $tip)
                        <li class="flex gap-2">
                            <span class="mt-1.5 h-1.5 w-1.5 shrink-0 rounded-full bg-(--color-theme-primary)"></span>
                            <span>{{ $tip }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif

            {{-- Prerequisites --}}
            @if(!empty($help['prerequisites']))
            <div>
                <h4 class="mb-2 text-xs font-semibold uppercase tracking-wide text-(--color-theme-warning)">Prerequisites</h4>
                <ul class="space-y-1.5 text-sm text-(--color-on-surface-muted)">
                    @foreach($help['prerequisites'] as $prereq)
                        <li class="flex gap-2">
                            <svg class="mt-0.5 h-4 w-4 shrink-0 text-(--color-theme-warning)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                            </svg>
                            @if(is_array($prereq))
                                <a href="{{ route($prereq['route']) }}" class="text-(--color-theme-primary) underline hover:text-(--color-theme-primary-hover)" wire:navigate>{{ $prereq['label'] }}</a>
                            @else
                                <span>{{ $prereq }}</span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>
            @endif
        </div>

        {{-- Related Pages --}}
        @if(!empty($help['related']))
        <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-(--color-theme-border) pt-3">
            <span class="text-xs font-medium text-(--color-on-surface-muted)">Related:</span>
            @foreach($help['related'] as $related)
                <a href="{{ route($related['route']) }}"
                   class="rounded-md bg-(--color-surface-raised) px-2 py-1 text-xs font-medium text-(--color-theme-primary) ring-1 ring-inset ring-(--color-theme-border) transition-colors hover:bg-(--color-surface)"
                   wire:navigate>
                    {{ $related['label'] }}
                </a>
            @endforeach
        </div>
        @endif
    </div>
</div>
@endif
