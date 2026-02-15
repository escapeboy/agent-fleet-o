<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $header ?? config('app.name') }}</title>

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @if(config('services.plausible.domain'))
        <script defer data-domain="{{ config('services.plausible.domain') }}" src="https://plausible.io/js/script.js"></script>
    @endif

    @livewireStyles
    @stack('styles')
</head>
<body class="bg-gray-50 font-sans antialiased">
    <div class="flex h-screen overflow-hidden">
        {{-- Sidebar --}}
        <x-sidebar />

        {{-- Main Content --}}
        <div class="flex flex-1 flex-col overflow-hidden">
            {{-- Top Bar --}}
            <header class="flex h-16 items-center justify-between border-b border-gray-200 bg-white px-6">
                <h1 class="text-lg font-semibold text-gray-900">
                    {{ $header ?? '' }}
                </h1>
                <div class="flex items-center gap-4">
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

            {{-- Page Content --}}
            <main class="flex-1 overflow-y-auto p-6">
                {{ $slot }}
            </main>
        </div>
    </div>

    @auth
        <livewire:assistant.assistant-panel />
    @endauth

    @livewireScripts
    @stack('scripts')
</body>
</html>
