@php
    $routeName = Route::currentRouteName();
    $help = config("page-help.{$routeName}");
@endphp

@if($help)
<button
    x-data
    @click="$dispatch('toggle-page-help')"
    class="flex h-8 w-8 items-center justify-center rounded-lg text-(--color-on-surface-muted) transition-colors hover:bg-(--color-surface-alt) hover:text-(--color-on-surface)"
    title="Page help"
    aria-label="Toggle page help"
>
    <svg class="h-[18px] w-[18px]" fill="none" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round"
              d="M9.879 7.519c1.171-1.025 3.071-1.025 4.242 0 1.172 1.025 1.172 2.687 0 3.712-.203.179-.43.326-.67.442-.745.361-1.45.999-1.45 1.827v.75M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9 5.25h.008v.008H12v-.008Z" />
    </svg>
</button>
@endif
