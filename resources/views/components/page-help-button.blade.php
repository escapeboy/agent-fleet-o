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
    <i class="fa-solid fa-circle-question text-[18px]"></i>
</button>
@endif
