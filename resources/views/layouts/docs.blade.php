@props([
    'title' => 'Documentation',
    'description' => 'FleetQ documentation — guides, API reference, and platform deep-dives.',
    'page' => '',
])
<x-layouts.public
    :title="$title . ' — FleetQ Docs'"
    :description="$description"
>
    <x-slot name="beforeMain">
        <x-landing.nav>
            <x-slot name="navLinks">
                <a href="{{ route('docs.index') }}" class="rounded text-sm font-medium text-primary-600 transition hover:text-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">Docs</a>
            </x-slot>
        </x-landing.nav>
    </x-slot>

    <div class="flex min-h-screen" x-data="{ mobileNav: false }">
        {{-- Mobile nav overlay --}}
        <div x-show="mobileNav"
             x-cloak
             @click="mobileNav = false"
             class="fixed inset-0 z-40 bg-gray-900/50 backdrop-blur-sm lg:hidden"
             aria-hidden="true"></div>

        {{-- Left sidebar --}}
        <aside class="fixed inset-y-0 left-0 z-40 w-72 overflow-y-auto border-r border-gray-200 bg-white pt-16 pb-8 lg:static lg:z-auto lg:w-64 lg:shrink-0 lg:pt-0"
               :class="mobileNav ? 'translate-x-0 shadow-xl' : '-translate-x-full lg:translate-x-0'"
               x-transition:enter="transition ease-out duration-200"
               x-transition:enter-start="-translate-x-full"
               x-transition:enter-end="translate-x-0"
               x-transition:leave="transition ease-in duration-150"
               x-transition:leave-start="translate-x-0"
               x-transition:leave-end="-translate-x-full">
            <x-docs.nav :current="$page" />
        </aside>

        {{-- Mobile nav toggle --}}
        <button @click="mobileNav = !mobileNav"
                class="fixed bottom-6 left-6 z-50 flex h-12 w-12 items-center justify-center rounded-full bg-gray-900 text-white shadow-lg lg:hidden"
                aria-label="Toggle docs navigation">
            <svg x-show="!mobileNav" class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/>
            </svg>
            <svg x-show="mobileNav" x-cloak class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
            </svg>
        </button>

        {{-- Main content --}}
        <main class="min-w-0 flex-1 px-6 py-10 sm:px-10 lg:py-12">
            <div class="mx-auto max-w-3xl">
                {{ $slot }}
            </div>
        </main>
    </div>
</x-layouts.public>
