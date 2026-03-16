<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Connect Account - {{ config('app.name') }}</title>
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
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-8">
            <h2 class="mb-2 text-lg font-semibold text-gray-900">Account already exists</h2>

            @if (! session('pending_social_link'))
                <p class="text-sm text-gray-500">Session expired. <a href="{{ route('login') }}" class="text-primary-600 hover:text-primary-700">Back to login</a></p>
            @else
                @php $pending = session('pending_social_link'); @endphp
                <p class="mb-6 text-sm text-gray-500">
                    An account with <strong>{{ $pending['email'] }}</strong> already exists.
                    Would you like to link your {{ ucfirst($pending['provider']) }} account to it?
                </p>

                @if ($errors->any())
                    <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-600">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('auth.social.do-merge') }}">
                    @csrf
                    <button type="submit"
                        class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        Yes, link accounts
                    </button>
                </form>

                <div class="mt-3">
                    <a href="{{ route('login') }}"
                       class="block w-full rounded-lg border border-gray-300 px-4 py-2.5 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Cancel — sign in with email instead
                    </a>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
