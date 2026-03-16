<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Two-Factor Authentication - {{ config('app.name') }}</title>
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

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-50 font-sans antialiased">
    <div class="w-full max-w-md">
        <div class="mb-8 text-center">
            <div class="mb-3 flex justify-center">
                <div class="flex h-12 w-12 items-center justify-center rounded-xl bg-primary-600">
                    <x-logo-icon class="h-7 w-7 text-white" />
                </div>
            </div>
            <h1 class="text-2xl font-bold text-gray-900">{{ config('app.name') }}</h1>
            <p class="mt-2 text-sm text-gray-500">Two-Factor Authentication</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-8">
            <h2 class="mb-2 text-lg font-semibold text-gray-900">Verify your identity</h2>

            <div x-data="{ useRecovery: false }">
                <p class="mb-6 text-sm text-gray-500" x-show="!useRecovery">
                    Enter the 6-digit code from your authenticator app.
                </p>
                <p class="mb-6 text-sm text-gray-500" x-show="useRecovery">
                    Enter one of your emergency recovery codes.
                </p>

                @if ($errors->any())
                    <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-600">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('two-factor.login') }}">
                    @csrf

                    <div class="mb-6" x-show="!useRecovery">
                        <x-form-input
                            label="Authentication Code"
                            type="text"
                            id="code"
                            name="code"
                            inputmode="numeric"
                            autofocus
                            autocomplete="one-time-code"
                        />
                    </div>

                    <div class="mb-6" x-show="useRecovery">
                        <x-form-input
                            label="Recovery Code"
                            type="text"
                            id="recovery_code"
                            name="recovery_code"
                            autocomplete="one-time-code"
                        />
                    </div>

                    <button type="submit"
                        class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        Verify
                    </button>
                </form>

                <button
                    type="button"
                    @click="useRecovery = !useRecovery"
                    class="mt-4 w-full text-center text-sm text-primary-600 hover:text-primary-700">
                    <span x-show="!useRecovery">Use a recovery code instead</span>
                    <span x-show="useRecovery">Use an authenticator code instead</span>
                </button>
            </div>
        </div>
    </div>
    @livewireScripts
</body>
</html>
