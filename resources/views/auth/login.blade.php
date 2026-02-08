<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - Agent Fleet</title>
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
            <h2 class="mb-6 text-lg font-semibold text-gray-900">Sign in</h2>

            @if ($errors->any())
                <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-600">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}">
                @csrf

                <div class="mb-4">
                    <x-form-input label="Email" type="email" id="email" name="email" :value="old('email')" required autofocus />
                </div>

                <div class="mb-6">
                    <x-form-input label="Password" type="password" id="password" name="password" required />
                </div>

                <div class="mb-6">
                    <x-form-checkbox id="remember" name="remember" label="Remember me" />
                </div>

                <button type="submit"
                    class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                    Sign in
                </button>

                <p class="mt-4 text-center text-sm text-gray-500">
                    No account yet? <a href="{{ route('register') }}" class="text-primary-600 hover:text-primary-700">Create one</a>
                </p>
            </form>
        </div>
    </div>
</body>
</html>
