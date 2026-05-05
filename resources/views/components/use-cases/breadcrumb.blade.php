@props(['title'])

<nav class="flex items-center gap-2 text-sm text-gray-500 mb-6" aria-label="Breadcrumb">
    <a href="{{ route('use-cases.index') }}" class="hover:text-primary-600 transition-colors">Use Cases</a>
    <i class="fa-solid fa-chevron-right text-base flex-shrink-0"></i>
    <span class="text-gray-900 font-medium truncate">{{ $title }}</span>
</nav>
