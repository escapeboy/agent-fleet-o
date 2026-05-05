<nav x-data="{ open: false, scrolled: false }"
     @scroll.window="scrolled = window.scrollY > 50"
     @keydown.escape.window="open = false"
     :class="scrolled ? 'bg-white/95 shadow-sm backdrop-blur-md' : 'bg-transparent'"
     class="fixed inset-x-0 top-0 z-50 transition-all duration-300"
     aria-label="Main navigation">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-6 py-4 lg:px-8">
        {{-- Logo --}}
        <a href="{{ url('/') }}" class="flex items-center gap-2.5">
            <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary-600">
                <x-logo-icon class="h-5 w-5 text-white" />
            </div>
            <span class="text-lg font-bold text-gray-900">FleetQ</span>
        </a>

        {{-- Desktop nav --}}
        <div class="hidden items-center gap-x-8 lg:flex">
            <a href="#features" class="rounded text-sm font-medium text-gray-600 transition hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">Features</a>
            <a href="#how-it-works" class="rounded text-sm font-medium text-gray-600 transition hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">How It Works</a>
            <a href="{{ route('marketplace.index') }}" class="rounded text-sm font-medium text-gray-600 transition hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">Marketplace</a>
            <a href="{{ route('use-cases.index') }}" class="rounded text-sm font-medium text-gray-600 transition hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">Use Cases</a>
            <a href="{{ route('docs.show', 'introduction') }}" class="rounded text-sm font-medium text-gray-600 transition hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">Docs</a>
            {{ $navLinks ?? '' }}
            @auth
                <a href="{{ route('dashboard') }}"
                   class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                    Dashboard
                </a>
            @else
                <a href="{{ route('login') }}" class="rounded text-sm font-semibold text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">Log in</a>
                <a href="{{ route('register') }}"
                   class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                    {{ $ctaLabel ?? 'Start Free' }}
                </a>
            @endauth
        </div>

        {{-- Mobile hamburger --}}
        <button @click="open = !open" class="rounded-md p-2.5 lg:hidden" :aria-expanded="open" aria-label="Toggle menu">
            <i x-show="!open" class="fa-solid fa-bars text-xl text-gray-700" aria-hidden="true"></i>
            <i x-show="open" x-cloak class="fa-solid fa-xmark text-xl text-gray-700" aria-hidden="true"></i>
        </button>
    </div>

    {{-- Mobile menu backdrop --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         @click="open = false"
         class="fixed inset-0 top-16 z-40 bg-gray-900/50 backdrop-blur-sm lg:hidden"
         aria-hidden="true"></div>

    {{-- Mobile menu --}}
    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         class="relative z-50 border-t border-gray-100 bg-white px-6 pb-6 pt-4 lg:hidden">
        <a href="#features" @click="open = false" class="block py-2.5 text-base font-medium text-gray-700">Features</a>
        <a href="#how-it-works" @click="open = false" class="block py-2.5 text-base font-medium text-gray-700">How It Works</a>
        <a href="{{ route('marketplace.index') }}" class="block py-2.5 text-base font-medium text-gray-700">Marketplace</a>
        <a href="{{ route('use-cases.index') }}" @click="open = false" class="block py-2.5 text-base font-medium text-gray-700">Use Cases</a>
        <a href="{{ route('docs.show', 'introduction') }}" @click="open = false" class="block py-2.5 text-base font-medium text-gray-700">Docs</a>
        {{ $mobileNavLinks ?? '' }}
        <div class="mt-4 flex flex-col gap-3">
            @auth
                <a href="{{ route('dashboard') }}" class="block rounded-lg bg-primary-600 px-4 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-primary-700">
                    Dashboard
                </a>
            @else
                <a href="{{ route('login') }}" class="block rounded-lg border border-gray-300 px-4 py-2.5 text-center text-sm font-semibold text-gray-700 transition hover:bg-gray-50">
                    Log in
                </a>
                <a href="{{ route('register') }}" class="block rounded-lg bg-primary-600 px-4 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-primary-700">
                    {{ $ctaLabel ?? 'Start Free' }}
                </a>
            @endauth
        </div>
    </div>
</nav>

{{-- Spacer for fixed nav --}}
<div class="h-16"></div>
