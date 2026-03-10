@props(['src' => '', 'alt' => '', 'caption' => null])

<figure class="my-6">
    <div class="overflow-hidden rounded-xl border border-gray-200 shadow-sm">
        <img src="{{ $src }}" alt="{{ $alt }}" class="w-full" loading="lazy">
    </div>
    @if ($caption)
        <figcaption class="mt-2 text-center text-xs text-gray-500">{{ $caption }}</figcaption>
    @endif
</figure>
