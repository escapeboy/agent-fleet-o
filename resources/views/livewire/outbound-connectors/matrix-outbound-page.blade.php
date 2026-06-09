<div>
    @if (session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    @if ($testMessage)
        <div class="mb-4 rounded-lg bg-green-50 p-4 text-sm text-green-700">{{ $testMessage }}</div>
    @endif

    @if ($testError)
        <div class="mb-4 rounded-lg bg-red-50 p-4 text-sm text-red-700">{{ $testError }}</div>
    @endif

    <div class="space-y-6">

        {{-- Status bar --}}
        <div class="flex items-center justify-between rounded-xl border border-gray-200 bg-white px-6 py-4">
            <div class="flex items-center gap-4">
                <div class="flex items-center gap-2">
                    <span class="inline-block h-2.5 w-2.5 rounded-full {{ $isActive ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                    <span class="text-sm font-medium text-gray-900">{{ $isActive ? 'Active' : 'Inactive' }}</span>
                </div>
                @if ($lastTestedAt)
                    <span class="text-sm text-gray-500">
                        Last tested {{ $lastTestedAt }}
                        <span class="ml-1 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $lastTestStatus === 'success' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                            {{ $lastTestStatus }}
                        </span>
                    </span>
                @endif
            </div>
            <div class="flex items-center gap-3">
                <label class="flex cursor-pointer items-center gap-2">
                    <input type="checkbox" wire:model="isActive" class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                    <span class="text-sm text-gray-700">Enabled</span>
                </label>
                <button wire:click="testConnection" wire:loading.attr="disabled"
                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                    <span wire:loading.remove wire:target="testConnection">Test Connection</span>
                    <span wire:loading wire:target="testConnection">Testing…</span>
                </button>
            </div>
        </div>

        {{-- Credentials --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Matrix Credentials</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Provide a bot
                    <a href="https://matrix.org/docs/guides/client-server-api/" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800">access token</a>
                    and the target room. Testing calls <code class="rounded bg-gray-100 px-1 text-xs">/account/whoami</code>.
                </p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                <x-form-input
                    wire:model="homeserverUrl"
                    label="Homeserver URL"
                    placeholder="https://matrix.org"
                    autocomplete="off"
                >
                    <x-slot:hint>Base URL of your Matrix homeserver.</x-slot:hint>
                </x-form-input>

                <x-form-input
                    wire:model="roomId"
                    label="Default Room ID"
                    placeholder="!abc123:matrix.org"
                    autocomplete="off"
                >
                    <x-slot:hint>Optional. Used when a proposal target doesn't specify a room_id.</x-slot:hint>
                </x-form-input>

                <x-form-input
                    wire:model="accessToken"
                    type="password"
                    label="Access Token"
                    placeholder="{{ $lastTestedAt || $homeserverUrl ? '(leave blank to keep existing)' : 'syt_…' }}"
                    autocomplete="new-password"
                >
                    <x-slot:hint>Bot access token (write-only).</x-slot:hint>
                </x-form-input>
            </div>
        </div>

        {{-- Save --}}
        <div class="flex justify-end">
            <button wire:click="save" wire:loading.attr="disabled"
                class="rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                <span wire:loading.remove wire:target="save">Save Configuration</span>
                <span wire:loading wire:target="save">Saving…</span>
            </button>
        </div>

    </div>
</div>
