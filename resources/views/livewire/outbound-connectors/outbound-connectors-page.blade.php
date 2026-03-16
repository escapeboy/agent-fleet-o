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
                @if($lastTestedAt)
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

        {{-- SMTP Server --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">SMTP Server</h2>
                <p class="mt-0.5 text-sm text-gray-500">Connection settings for your mail server.</p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-3">
                <div class="sm:col-span-2">
                    <x-form-input wire:model="host" label="SMTP Host" placeholder="smtp.example.com" />
                </div>
                <x-form-input wire:model="port" type="number" label="Port" placeholder="587" />
                <div>
                    <x-form-select wire:model="encryption" label="Encryption">
                        <option value="tls">TLS (STARTTLS)</option>
                        <option value="ssl">SSL</option>
                        <option value="none">None</option>
                    </x-form-select>
                </div>
                <x-form-input wire:model="username" label="Username" placeholder="user@example.com" autocomplete="off" />
                <x-form-input wire:model="password" type="password" label="Password" placeholder="{{ $host ? '(leave blank to keep existing)' : '' }}" autocomplete="new-password" />
            </div>
        </div>

        {{-- Sender Identity --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Sender Identity</h2>
                <p class="mt-0.5 text-sm text-gray-500">The "From" address shown to email recipients.</p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                <x-form-input wire:model="fromAddress" type="email" label="From Address" placeholder="noreply@example.com" />
                <x-form-input wire:model="fromName" label="From Name" placeholder="My Company" />
            </div>
        </div>

        {{-- Delivery Defaults --}}
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-200 px-6 py-4">
                <h2 class="text-base font-semibold text-gray-900">Delivery Defaults</h2>
                <p class="mt-0.5 text-sm text-gray-500">
                    Used when an agent generates email output without specifying a recipient or template.
                </p>
            </div>
            <div class="grid grid-cols-1 gap-5 p-6 sm:grid-cols-2">
                <div>
                    <x-form-input
                        wire:model="defaultRecipient"
                        type="email"
                        label="Default Recipient"
                        placeholder="you@example.com"
                    >
                        <x-slot:hint>Email address to send to when the agent doesn't specify one.</x-slot:hint>
                    </x-form-input>
                </div>
                <div>
                    <x-form-select wire:model="defaultTemplateId" label="Default Email Template">
                        <option value="">— No template (plain text) —</option>
                        @foreach($templates as $template)
                            <option value="{{ $template->id }}">{{ $template->name }}</option>
                        @endforeach
                    </x-form-select>
                    @if($templates->isEmpty())
                        <p class="mt-1.5 text-xs text-gray-400">
                            No active templates yet.
                            <a href="{{ route('email.templates.index') }}" class="text-primary-600 hover:text-primary-800">Create one →</a>
                        </p>
                    @endif
                </div>
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
