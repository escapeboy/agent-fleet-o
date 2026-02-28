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
    <div class="flex h-screen overflow-hidden">
        {{-- Sidebar --}}
        <x-sidebar />

        {{-- Main Content --}}
        <div class="flex flex-1 flex-col overflow-hidden">
            {{-- Top Bar --}}
            <header class="flex h-16 items-center justify-between border-b border-(--color-header-border) bg-(--color-header-bg) px-6">
                <h1 class="text-lg font-semibold text-(--color-on-surface)">
                    {{ $header ?? '' }}
                </h1>
                <div class="flex items-center gap-4">
                    @auth
                        <livewire:shared.notification-bell />
                        <livewire:components.theme-switcher />
                    @endauth
                    <span class="text-sm text-gray-500">{{ auth()->user()?->name ?? 'Admin' }}</span>
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

            {{-- Update notification banner (shown when a newer version is available) --}}
            @auth
                <livewire:shared.update-banner />
            @endauth

            {{-- Page Content --}}
            <main data-theme-scope class="flex-1 overflow-y-auto p-6">
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
