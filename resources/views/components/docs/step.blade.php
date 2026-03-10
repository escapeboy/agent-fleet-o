@props(['number' => 1, 'title' => ''])

<div class="my-6 flex gap-4">
    <div class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-primary-600 text-sm font-bold text-white">
        {{ $number }}
    </div>
    <div class="flex-1 pt-1">
        @if ($title)
            <h3 class="text-base font-semibold text-gray-900">{{ $title }}</h3>
        @endif
        <div class="mt-1.5 text-sm text-gray-600">
            {{ $slot }}
        </div>
    </div>
</div>
