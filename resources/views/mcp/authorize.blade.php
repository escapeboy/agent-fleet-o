<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — Authorize Access</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-gray-50 flex flex-col items-center justify-center py-12 px-4">
    <div class="max-w-md w-full bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
        {{-- Header --}}
        <div class="px-8 py-6 border-b border-gray-100">
            <div class="flex items-center gap-3 mb-1">
                <div class="w-8 h-8 bg-primary-600 rounded-lg flex items-center justify-center text-white font-bold text-sm">
                    {{ substr(config('app.name'), 0, 1) }}
                </div>
                <span class="text-sm font-medium text-gray-500">{{ config('app.name') }}</span>
            </div>
            <h1 class="text-xl font-semibold text-gray-900 mt-3">Authorize Access</h1>
            <p class="text-sm text-gray-500 mt-1">
                <strong class="text-gray-800">{{ $client->name }}</strong>
                wants to access your FleetQ account via the MCP server.
            </p>
        </div>

        {{-- Scopes --}}
        <div class="px-8 py-5">
            <p class="text-xs font-semibold text-gray-400 uppercase tracking-wide mb-3">This will allow the application to:</p>
            <ul class="space-y-2">
                @foreach ($scopes as $scope)
                    <li class="flex items-start gap-2.5 text-sm text-gray-700">
                        <svg class="w-4 h-4 text-green-500 mt-0.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/>
                        </svg>
                        {{ $scope->description ?? $scope->id }}
                    </li>
                @endforeach
            </ul>
        </div>

        {{-- Signed-in as --}}
        <div class="px-8 py-4 bg-gray-50 border-t border-gray-100 text-sm text-gray-500">
            Signed in as <strong class="text-gray-800">{{ auth()->user()->email }}</strong>
        </div>

        {{-- Actions --}}
        <div class="px-8 py-5 flex gap-3">
            {{-- Deny --}}
            <form method="POST" action="{{ route('passport.authorizations.deny') }}" class="flex-1">
                @csrf
                @method('DELETE')
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="w-full py-2.5 px-4 rounded-lg border border-gray-300 text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 transition-colors">
                    Deny
                </button>
            </form>

            {{-- Approve --}}
            <form method="POST" action="{{ route('passport.authorizations.approve') }}" class="flex-1">
                @csrf
                <input type="hidden" name="state" value="{{ $request->state }}">
                <input type="hidden" name="client_id" value="{{ $client->getKey() }}">
                <input type="hidden" name="auth_token" value="{{ $authToken }}">
                <button type="submit" class="w-full py-2.5 px-4 rounded-lg bg-primary-600 text-sm font-medium text-white hover:bg-primary-700 transition-colors">
                    Authorize
                </button>
            </form>
        </div>
    </div>

    <p class="mt-6 text-xs text-gray-400 text-center max-w-xs">
        Authorizing will grant <strong>{{ $client->name }}</strong> access until you revoke it from your account settings.
    </p>
</body>
</html>
