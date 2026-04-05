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

    @vite(['resources/css/app.css', 'resources/js/app.js', 'resources/js/push-notifications.js', 'resources/js/pwa-features.js'])

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
    {{-- Offline banner — shown when device loses connectivity --}}
    <div x-data="networkStatus" x-show="!isOnline"
         x-transition:enter="transition ease-out duration-300" x-transition:enter-start="-translate-y-full" x-transition:enter-end="translate-y-0"
         x-transition:leave="transition ease-in duration-200" x-transition:leave-start="translate-y-0" x-transition:leave-end="-translate-y-full"
         class="fixed inset-x-0 top-0 z-[100] flex items-center justify-center gap-2 bg-amber-500 px-4 py-2 text-sm font-medium text-white shadow"
         style="display: none;">
        <i class="fa-solid fa-triangle-exclamation text-base shrink-0"></i>
        You're offline — some features are unavailable. Changes will sync when you reconnect.
    </div>
    <div class="flex h-screen overflow-hidden" x-data="{ sidebarOpen: false, nav: null }" @keydown.escape.window="sidebarOpen = false; nav = null">
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
                        <i class="fa-solid fa-bars text-lg"></i>
                    </button>
                    <h1 class="truncate text-base font-semibold text-(--color-on-surface) lg:text-lg">
                        {{ $header ?? '' }}
                    </h1>
                </div>
                <div class="flex shrink-0 items-center gap-2 lg:gap-4">
                    @auth
                        <span class="hidden sm:inline"><x-page-help-button /></span>
                        <livewire:shared.notification-bell />
                        <livewire:components.theme-switcher />

                        {{-- User dropdown --}}
                        <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape.window="open = false">
                            <button @click="open = !open"
                                    class="flex items-center gap-2 rounded-full p-1.5 hover:bg-gray-100 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-1"
                                    aria-haspopup="true" :aria-expanded="open">
                                <span class="flex h-7 w-7 items-center justify-center rounded-full bg-primary-100 text-xs font-semibold text-primary-700 uppercase select-none shrink-0">
                                    {{ mb_strtoupper(mb_substr(auth()->user()->name ?? 'U', 0, 2)) }}
                                </span>
                                <span class="hidden sm:block text-sm font-medium text-(--color-on-surface) max-w-28 truncate">
                                    {{ auth()->user()->name }}
                                </span>
                                <i class="fa-solid fa-chevron-down hidden sm:block text-sm text-gray-400 shrink-0"></i>
                            </button>

                            <div x-show="open"
                                 x-transition:enter="transition ease-out duration-100"
                                 x-transition:enter-start="opacity-0 scale-95"
                                 x-transition:enter-end="opacity-100 scale-100"
                                 x-transition:leave="transition ease-in duration-75"
                                 x-transition:leave-start="opacity-100 scale-100"
                                 x-transition:leave-end="opacity-0 scale-95"
                                 class="absolute right-0 z-50 mt-2 w-56 origin-top-right rounded-xl border border-gray-200 bg-white py-1 shadow-lg"
                                 style="display: none;">
                                {{-- User info --}}
                                <div class="border-b border-gray-100 px-4 py-3">
                                    <p class="text-sm font-medium text-gray-900 truncate">{{ auth()->user()->name }}</p>
                                    <p class="text-xs text-gray-500 truncate">{{ auth()->user()->email }}</p>
                                </div>
                                {{-- Nav links --}}
                                <div class="py-1">
                                    @if(Route::has('profile'))
                                        <a href="{{ route('profile') }}"
                                           class="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                            <i class="fa-solid fa-user text-base text-gray-400 shrink-0"></i>
                                            Profile Settings
                                        </a>
                                    @endif
                                    <a href="{{ route('notifications.preferences') }}"
                                       class="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                        <i class="fa-solid fa-bell text-base text-gray-400 shrink-0"></i>
                                        Notifications
                                    </a>
                                    <a href="{{ route('team.settings') }}"
                                       class="flex items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                        <i class="fa-solid fa-users text-base text-gray-400 shrink-0"></i>
                                        Team Settings
                                    </a>
                                </div>
                                {{-- Sign out --}}
                                <div class="border-t border-gray-100 py-1">
                                    <form method="POST" action="{{ route('logout') }}">
                                        @csrf
                                        <button type="submit"
                                                class="flex w-full items-center gap-2.5 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors">
                                            <i class="fa-solid fa-arrow-right-from-bracket text-base text-gray-400 shrink-0"></i>
                                            Sign Out
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
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

    {{--
        Alpine.data components MUST be registered here — before @livewireScripts loads Alpine.
        @livewireScripts injects a regular (non-deferred) <script> that starts Alpine synchronously.
        Vite assets are <script type="module"> (always deferred) so terminal.js runs too late.
    --}}
    <script>
        document.addEventListener('alpine:init', () => {
            // ── Terminal stubs (step-terminal-panel, multi-terminal-panel) ──────────
            // terminal.js is a deferred Vite module — loads AFTER Alpine starts.
            // These stubs prevent ReferenceError; terminal.js upgrades them with real xterm.
            Alpine.data('stepTerminal', () => ({
                terminal: null, fitAddon: null, lastLength: 0, _pending: null,
                init() {
                    (window.__xtermQ = window.__xtermQ || []).push(this);
                    window.__xtermInit && window.__xtermInit(this);
                },
                appendOutput(text) {
                    if (this.terminal) {
                        if (text && text.length > this.lastLength) {
                            this.terminal.write(text.substring(this.lastLength));
                            this.lastLength = text.length;
                        }
                    } else {
                        this._pending = text;
                    }
                },
                destroy() { this.terminal && this.terminal.dispose(); },
            }));

            Alpine.data('multiTerminal', () => ({
                tabs: [], activeTabId: null, terminals: {}, fitAddons: {}, lastLengths: {}, observer: null,
                init() {
                    (window.__xtermMQ = window.__xtermMQ || []).push(this);
                    window.__xtermMultiInit && window.__xtermMultiInit(this);
                },
                addTab(id, label) {
                    if (this.tabs.find(t => t.id === id)) { this.activeTabId = id; return; }
                    this.tabs.push({ id, label: label || ('Tab ' + (this.tabs.length + 1)) });
                    this.activeTabId = id;
                },
                removeTab(id) {
                    if (this.terminals[id]) { this.terminals[id].dispose(); delete this.terminals[id]; delete this.fitAddons[id]; delete this.lastLengths[id]; }
                    this.tabs = this.tabs.filter(t => t.id !== id);
                    if (this.activeTabId === id) { this.activeTabId = this.tabs.length ? this.tabs[this.tabs.length - 1].id : null; }
                },
                switchTab(id) { this.activeTabId = id; },
                appendOutput(id, text) {},
                clearTab(id) {},
                destroy() { Object.values(this.terminals).forEach(t => t && t.dispose()); this.observer && this.observer.disconnect(); },
            }));
        });
    </script>

    @livewireScripts
    @stack('scripts')

    {{-- PWA: install prompt + update notification --}}
    <x-pwa-install-prompt />
    <x-pwa-update-toast />

    {{-- WebMCP: polyfill for browser AI agent tool discovery.
         Suppressed on pages that set $suppressWebmcp (e.g. the GrapesJS builder) to
         prevent its DOM-mutation observer from interfering with editor internals. --}}
    @if(config('webmcp.enabled', true) && empty($suppressWebmcp))
        <script src="https://unpkg.com/@mcp-b/global@2.2.0/dist/index.iife.js" defer></script>
    @endif
</body>
</html>
