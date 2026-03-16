<div class="max-w-xl">
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="border-b border-gray-100 px-6 py-4">
            <h3 class="text-sm font-semibold text-gray-900">Connected Accounts</h3>
            <p class="mt-1 text-sm text-gray-500">Link social accounts to sign in without a password.</p>
        </div>

        @if(session()->has('unlink_error'))
            <div class="mx-6 mt-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
                {{ session('unlink_error') }}
            </div>
        @endif

        @if(session()->has('unlink_success'))
            <div class="mx-6 mt-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
                {{ session('unlink_success') }}
            </div>
        @endif

        <ul class="divide-y divide-gray-100 px-6 py-2">
            @foreach($providers as $provider)
                @php
                    $connected = $connectedAccounts->firstWhere('provider', $provider['key']);
                @endphp
                <li class="flex items-center justify-between py-4">
                    <div class="flex items-center gap-3">
                        {{-- Provider icon / initial --}}
                        <span class="flex h-8 w-8 items-center justify-center rounded-full bg-gray-100 text-xs font-bold text-gray-600 uppercase shrink-0">
                            {{ substr($provider['name'], 0, 1) }}
                        </span>
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $provider['name'] }}</p>
                            @if($connected)
                                <p class="text-xs text-gray-500">{{ $connected->email ?? $connected->name ?? 'Connected' }}</p>
                            @else
                                <p class="text-xs text-gray-400">Not connected</p>
                            @endif
                        </div>
                    </div>
                    <div>
                        @if($connected)
                            <button
                                wire:click="unlink('{{ $provider['key'] }}')"
                                wire:confirm="Disconnect {{ $provider['name'] }}? You won't be able to sign in with it."
                                class="text-sm text-red-500 hover:text-red-700 focus:outline-none focus:underline">
                                Disconnect
                            </button>
                        @else
                            <a href="{{ route('auth.social.link', $provider['key']) }}"
                               class="text-sm text-primary-600 hover:text-primary-800 focus:outline-none focus:underline">
                                Connect
                            </a>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>

    <p class="mt-3 text-xs text-gray-400">
        You cannot disconnect a provider if it's your only way to sign in. Set a password first to unlock disconnection.
    </p>
</div>
