<div class="space-y-6">
    {{-- Flash Messages --}}
    @if(session()->has('message'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif
    @if(session()->has('error'))
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Page Header --}}
    <div class="flex items-center justify-between">
        <div>
            <p class="mt-1 text-sm text-gray-500">Connect external services to receive signals and execute actions.</p>
        </div>
    </div>

    {{-- Connected Integrations --}}
    @if($connectedIntegrations->isNotEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Connected</h2>
            <div class="space-y-3">
                @foreach($connectedIntegrations as $integration)
                    <div class="flex items-center justify-between rounded-lg border border-gray-100 p-4">
                        <div>
                            <span class="font-medium text-gray-900">{{ $integration->name }}</span>
                            <span class="ml-2 text-xs text-gray-500">{{ ucfirst($integration->driver) }}</span>
                            @php $status = $integration->status; @endphp
                            <span class="ml-2 inline-flex rounded-full px-2 py-0.5 text-xs {{ $status->color() }}">
                                {{ $status->label() }}
                            </span>
                            @if($integration->last_pinged_at)
                                <span class="ml-2 text-xs text-gray-400">Pinged {{ $integration->last_pinged_at->diffForHumans() }}</span>
                            @endif
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="{{ route('integrations.show', $integration) }}"
                               class="text-sm text-primary-600 hover:text-primary-800">Details</a>
                            <button wire:click="ping('{{ $integration->id }}')"
                                    class="text-sm text-gray-500 hover:text-gray-700">Ping</button>
                            <button wire:click="disconnect('{{ $integration->id }}')"
                                    wire:confirm="Disconnect this integration?"
                                    class="text-sm text-red-600 hover:text-red-800">Disconnect</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Available Drivers Gallery --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <div class="mb-4 flex items-center justify-between gap-4">
            <h2 class="text-lg font-semibold text-gray-900">Available Integrations</h2>
            <input
                type="search"
                wire:model.live.debounce.200ms="search"
                placeholder="Filter integrations…"
                class="w-56 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500 focus:outline-none"
            />
        </div>
        @php
            $authLabels = [
                'api_key'      => 'API Key',
                'oauth2'       => 'OAuth 2.0',
                'webhook_only' => 'Webhook Only',
                'basic_auth'   => 'Basic Auth',
            ];
        @endphp
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
            @foreach($availableDrivers as $slug => $info)
                <div class="rounded-lg border border-gray-100 p-4 hover:border-primary-200 hover:bg-primary-50">
                    <div class="mb-2 text-2xl">{{ $info['icon'] ?? '🔌' }}</div>
                    <p class="font-medium text-gray-900">{{ $info['label'] }}</p>
                    <p class="mt-0.5 text-xs text-gray-500">{{ $authLabels[$info['auth']] ?? ucfirst($info['auth']) }}</p>
                    <button wire:click="openConnectForm('{{ $slug }}')"
                            class="mt-3 rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                        Connect
                    </button>
                </div>
            @endforeach
            @if($availableDrivers->isEmpty() ?? count($availableDrivers) === 0)
                <div class="col-span-full py-8 text-center text-sm text-gray-400">No integrations match "{{ $search }}".</div>
            @endif
        </div>
    </div>

    {{-- Connect Form Modal --}}
    @if($showConnectForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50">
            <div class="w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <h3 class="text-lg font-semibold text-gray-900">Connect {{ ucfirst($connectDriver) }}</h3>
                    <button wire:click="closeConnectForm" class="text-gray-400 hover:text-gray-600">&times;</button>
                </div>

                <div class="space-y-4">
                    <x-form-input wire:model="connectName" label="Integration Name" type="text"
                        placeholder="e.g. Production GitHub" :error="$errors->first('connectName')" />

                    @php
                        $driverConfig = $availableDrivers[$connectDriver] ?? [];
                    @endphp

                    @if(($driverConfig['auth'] ?? '') === 'oauth2')
                        @if(!empty($driverConfig['subdomain_required']))
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Subdomain <span class="text-red-500">*</span></label>
                                <input wire:model="connectConfig.subdomain" type="text"
                                       placeholder="yourcompany"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 focus:outline-none"
                                       required />
                                <p class="mt-1 text-xs text-gray-500">From <span class="font-mono">yourcompany.{{ $connectDriver }}.com</span></p>
                            </div>
                        @endif
                        <div class="rounded-lg border border-blue-100 bg-blue-50 p-4 text-sm text-blue-700">
                            <p class="font-medium">OAuth2 Authorization Required</p>
                            <p class="mt-1 text-blue-600">You'll be redirected to {{ ucfirst($connectDriver) }} to authorize access. Make sure to return here after authorizing.</p>
                        </div>
                    @elseif(($driverConfig['auth'] ?? '') === 'webhook_only')
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm text-gray-600">
                            <p class="font-medium text-gray-700">No credentials required</p>
                            <p class="mt-1">This integration receives data via webhook. After connecting, you'll get a unique URL to paste into {{ ucfirst($connectDriver) }}.</p>
                        </div>
                    @elseif(!empty($credentialSchema))
                        <div class="space-y-3">
                            @foreach($credentialSchema as $fieldKey => $field)
                                @php
                                    $fieldType = ($field['type'] ?? 'string') === 'password' ? 'password' : 'text';
                                    $isRequired = $field['required'] ?? false;
                                @endphp
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">
                                        {{ $field['label'] ?? $fieldKey }}
                                        @if(!$isRequired)
                                            <span class="ml-1 text-xs font-normal text-gray-400">optional</span>
                                        @endif
                                    </label>
                                    <input
                                        wire:model="connectCredentials.{{ $fieldKey }}"
                                        type="{{ $fieldType }}"
                                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500 focus:outline-none"
                                        {{ $isRequired ? 'required' : '' }}
                                    />
                                    @if(!empty($field['hint']))
                                        <p class="mt-1 text-xs text-gray-500">{{ $field['hint'] }}</p>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @else
                        <div class="space-y-2">
                            <label class="mb-1 block text-sm font-medium text-gray-700">Credentials</label>
                            <div class="flex gap-2">
                                <input wire:model="credentialKey" placeholder="Field name"
                                    class="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500 focus:outline-none" />
                                <input wire:model="credentialValue" type="password" placeholder="Value"
                                    class="flex-1 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500 focus:outline-none" />
                                <button wire:click="addCredential"
                                        class="rounded-lg bg-gray-100 px-3 py-1.5 text-sm hover:bg-gray-200">Add</button>
                            </div>
                            @foreach($connectCredentials as $key => $value)
                                <div class="flex items-center justify-between rounded bg-gray-50 px-3 py-1.5 text-sm">
                                    <span class="text-gray-700">{{ $key }}: <span class="text-gray-400">••••••••</span></span>
                                    <button wire:click="removeCredential('{{ $key }}')" class="text-red-400 hover:text-red-600">&times;</button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="mt-6 flex justify-end gap-3">
                    <button wire:click="closeConnectForm"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    @if(($driverConfig['auth'] ?? '') === 'oauth2')
                        <button wire:click="connectOAuth"
                                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Continue with {{ ucfirst($connectDriver) }} →
                        </button>
                    @else
                        <button wire:click="connect"
                                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Connect
                        </button>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
