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
                <h2 class="text-base font-semibold text-gray-900">Supabase Realtime Broadcast</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Pushes agent output to a
                    <a href="https://supabase.com/docs/guides/realtime/broadcast" target="_blank" rel="noopener" class="text-primary-600 hover:text-primary-800">Realtime Broadcast</a>
                    channel. Testing sends a test broadcast to the channel below.
                </p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                <x-form-input
                    wire:model="ref"
                    label="Project Ref"
                    placeholder="xyzabcdef"
                    autocomplete="off"
                >
                    <x-slot:hint>Supabase project reference ID (the subdomain).</x-slot:hint>
                </x-form-input>

                <x-form-input
                    wire:model="apiKey"
                    type="password"
                    label="API Key"
                    placeholder="{{ $lastTestedAt || $ref ? '(leave blank to keep existing)' : 'anon or service role key' }}"
                    autocomplete="new-password"
                >
                    <x-slot:hint>Anon or service-role key (write-only).</x-slot:hint>
                </x-form-input>

                <x-form-input
                    wire:model="channel"
                    label="Channel Topic"
                    placeholder="agent:results"
                    autocomplete="off"
                >
                    <x-slot:hint>Realtime channel topic clients subscribe to.</x-slot:hint>
                </x-form-input>

                <x-form-input
                    wire:model="event"
                    label="Event Name"
                    placeholder="message"
                    autocomplete="off"
                >
                    <x-slot:hint>Broadcast event name.</x-slot:hint>
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
