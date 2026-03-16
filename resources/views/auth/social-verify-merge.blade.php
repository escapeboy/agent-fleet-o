<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verify Account Link - {{ config('app.name') }}</title>
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
            <h2 class="mb-2 text-lg font-semibold text-gray-900">Check your email</h2>

            @if (! session('pending_social_link'))
                <p class="text-sm text-gray-500">Session expired. <a href="{{ route('login') }}" class="text-primary-600 hover:text-primary-700">Back to login</a></p>
            @else
                @php $pending = session('pending_social_link'); @endphp
                <p class="mb-6 text-sm text-gray-500">
                    We sent a 6-digit verification code to
                    <strong>{{ $pending['email'] }}</strong>.
                    Enter it below to link your {{ ucfirst($pending['provider']) }} account.
                </p>

                @if ($errors->any())
                    <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-600">
                        @foreach ($errors->all() as $error)
                            <p>{{ $error }}</p>
                        @endforeach
                    </div>
                @endif

                <form method="POST" action="{{ route('auth.social.verify-merge.submit') }}">
                    @csrf
                    <div class="mb-6">
                        <x-form-input
                            label="Verification code"
                            type="text"
                            id="otp"
                            name="otp"
                            :value="old('otp')"
                            placeholder="000000"
                            maxlength="6"
                            inputmode="numeric"
                            autocomplete="one-time-code"
                            required
                            autofocus
                        />
                    </div>

                    <button type="submit"
                        class="w-full rounded-lg bg-primary-600 px-4 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        Verify and link accounts
                    </button>
                </form>

                <div class="mt-4 text-center">
                    <a href="{{ route('login') }}" class="text-sm text-gray-500 hover:text-gray-700">Cancel</a>
                </div>
            @endif
        </div>
    </div>
</body>
</html>
