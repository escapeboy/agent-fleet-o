<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Accept Invitation - Agent Fleet</title>
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
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Team Invitation</h2>

            <p class="mb-2 text-sm text-gray-600">
                You've been invited to join <strong>{{ $invitation->team->name }}</strong>
                as a <strong>{{ ucfirst($invitation->role) }}</strong>.
            </p>

            <p class="mb-6 text-sm text-gray-500">
                Invited by {{ $invitation->inviter->name }} ({{ $invitation->inviter->email }})
            </p>

            @auth
                <form method="POST" action="{{ url("/invitations/{$invitation->token}/accept") }}">
                    @csrf
                    <button type="submit"
                        class="w-full rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        Accept Invitation
                    </button>
                </form>
            @else
                <div class="space-y-3">
                    <a href="{{ route('login') }}"
                        class="block w-full rounded-lg bg-primary-600 px-4 py-2 text-center text-sm font-medium text-white hover:bg-primary-700">
                        Sign in to accept
                    </a>
                    <a href="{{ route('register') }}"
                        class="block w-full rounded-lg border border-gray-300 px-4 py-2 text-center text-sm font-medium text-gray-700 hover:bg-gray-50">
                        Create an account
                    </a>
                </div>
            @endauth
        </div>
    </div>
</body>
</html>
