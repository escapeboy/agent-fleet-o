<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Email - Agent Fleet</title>
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />
    @vite(['resources/css/app.css'])
</head>
<body class="flex min-h-screen items-center justify-center bg-gray-50 font-sans antialiased">
    <div class="w-full max-w-md">
        <div class="mb-8 text-center">
            <h1 class="text-2xl font-bold text-gray-900">Agent Fleet</h1>
            <p class="mt-2 text-sm text-gray-500">AI Agent Mission Control</p>
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-8">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Verify your email</h2>

            <p class="mb-4 text-sm text-gray-600">
                We sent a verification link to <strong>{{ auth()->user()->email }}</strong>.
                Please check your inbox and click the link to verify your account.
            </p>

            @if (session('status') == 'verification-link-sent')
                <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
                    A new verification link has been sent to your email address.
                </div>
            @endif

            <form method="POST" action="{{ route('verification.send') }}">
                @csrf
                <button type="submit"
                    class="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    Resend verification email
                </button>
            </form>

            <form method="POST" action="{{ route('logout') }}" class="mt-4">
                @csrf
                <button type="submit" class="w-full text-center text-sm text-gray-500 hover:text-gray-700">
                    Sign out
                </button>
            </form>
        </div>
    </div>
</body>
</html>
