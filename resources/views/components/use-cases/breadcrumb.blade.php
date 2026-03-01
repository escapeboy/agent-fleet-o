@props(['title'])

<nav class="flex items-center gap-2 text-sm text-gray-500 mb-6" aria-label="Breadcrumb">
    <a href="{{ route('use-cases.index') }}" class="hover:text-primary-600 transition-colors">Use Cases</a>
    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
    </svg>
    <span class="text-gray-900 font-medium truncate">{{ $title }}</span>
</nav>
