<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" data-theme="{{ auth()->user()?->theme ?? 'default' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="vapid-public-key" content="{{ config('webpush.vapid.public_key', '') }}">

    <title>{{ $header ?? config('app.name') }}</title>

    {{-- Favicons & PWA --}}
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="/favicon.ico" sizes="16x16 32x32 48x48">
    <link rel="apple-touch-icon" href="/apple-touch-icon.png">
    <link rel="manifest" href="/site.webmanifest">
    <meta name="theme-color" content="#2563eb">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="FleetQ">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="format-detection" content="telephone=no">
    <meta name="msapplication-TileColor" content="#2563eb">
    <meta name="msapplication-config" content="/browserconfig.xml">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/push-notifications.js'])

    @if(config('services.plausible.domain'))
        <script defer data-domain="{{ config('services.plausible.domain') }}" src="https://plausible.io/js/script.js"></script>
    @endif
    @if(config('services.google_analytics.id'))
        <script async src="https://www.googletagmanager.com/gtag/js?id={{ config('services.google_analytics.id') }}"></script>
        <script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config','{{ config('services.google_analytics.id') }}');</script>
    @endif

    @livewireStyles
    @stack('styles')

    {{-- Prevent theme flash — apply saved theme before paint --}}
    <script>
        (function() {
            var t = document.documentElement.getAttribute('data-theme') || localStorage.getItem('fleetq-theme') || 'default';
            document.documentElement.setAttribute('data-theme', t);
        })();
    </script>
</head>
<body class="bg-(--color-surface-alt) font-sans antialiased text-(--color-on-surface)">
    <div class="flex h-screen overflow-hidden" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
        {{-- Mobile overlay backdrop --}}
        <div x-show="sidebarOpen"
             x-transition:enter="transition-opacity ease-linear duration-200"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition-opacity ease-linear duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             @click="sidebarOpen = false"
             class="fixed inset-0 z-40 bg-black/50 lg:hidden"
             style="display: none;"></div>

        {{-- Sidebar --}}
        <x-sidebar />

        {{-- Main Content --}}
        <div class="flex min-w-0 flex-1 flex-col overflow-hidden">
            {{-- Top Bar --}}
            <header class="flex h-16 shrink-0 items-center justify-between border-b border-(--color-header-border) bg-(--color-header-bg) px-4 lg:px-6">
                <div class="flex min-w-0 items-center gap-2">
                    {{-- Mobile hamburger --}}
                    <button @click="sidebarOpen = !sidebarOpen"
                            class="lg:hidden -ml-1 shrink-0 rounded-md p-2 text-gray-500 hover:bg-gray-100 hover:text-gray-700"
                            aria-label="Toggle navigation">
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/>
                        </svg>
                    </button>
                    <h1 class="truncate text-base font-semibold text-(--color-on-surface) lg:text-lg">
                        {{ $header ?? '' }}
                    </h1>
                </div>
                <div class="flex shrink-0 items-center gap-2 lg:gap-4">
                    @auth
                        <x-page-help-button />
                        <livewire:shared.notification-bell />
                        <livewire:components.theme-switcher />
                    @endauth
                    <span class="hidden text-sm text-gray-500 sm:inline">{{ auth()->user()?->name ?? 'Admin' }}</span>
                    @auth
                        <form method="POST" action="{{ route('logout') }}">
                            @csrf
                            <button type="submit" class="text-sm text-gray-500 hover:text-gray-700">
                                Logout
                            </button>
                        </form>
                    @endauth
                </div>
            </header>

            {{-- Update notification banner — self-hosted only (cloud manages its own deployments) --}}
            @selfhosted
                @auth
                    <livewire:shared.update-banner />
                @endauth
            @endselfhosted

            {{-- Page Content --}}
            <main data-theme-scope class="flex-1 overflow-y-auto p-4 lg:p-6">
                <x-page-help />
                {{ $slot }}
            </main>
        </div>
    </div>

    @auth
        <livewire:assistant.assistant-panel />
    @endauth

    @livewireScripts
    @stack('scripts')

    {{-- PWA: install prompt + update notification --}}
    <x-pwa-install-prompt />
    <x-pwa-update-toast />
</body>
</html>
