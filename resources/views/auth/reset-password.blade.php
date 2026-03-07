<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password - {{ config('app.name') }}</title>
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
            <p class="mt-2 text-sm text-gray-500">AI Agent Mission Control</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-8">
            <h2 class="mb-6 text-lg font-semibold text-gray-900">Set new password</h2>

            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-600">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('password.update') }}">
                @csrf

                <input type="hidden" name="token" value="{{ $request->route('token') }}">

                <div class="mb-4">
                    <x-form-input label="Email" type="email" id="email" name="email"
                        :value="old('email', $request->email)"
                        required autocomplete="username" />
                </div>

                <div class="mb-4">
                    <x-form-input label="New Password" type="password" id="password" name="password"
                        required autofocus autocomplete="new-password" />
                </div>

                <div class="mb-6">
                    <x-form-input label="Confirm Password" type="password" id="password_confirmation"
                        name="password_confirmation" required autocomplete="new-password" />
                </div>

                <button type="submit"
                    class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    Reset Password
                </button>
            </form>
        </div>
    </div>
</body>
</html>
