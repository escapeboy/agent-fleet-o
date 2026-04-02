<div class="space-y-6">
    {{-- Flash Messages --}}
    @if(session()->has('message'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700" x-data x-init="setTimeout(() => $el.remove(), 5000)">{{ session('message') }}</div>
    @endif
    @if(session()->has('error'))
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700" x-data x-init="setTimeout(() => $el.remove(), 5000)">{{ session('error') }}</div>
    @endif

    {{-- Page Header --}}
    <div>
        <p class="text-sm text-gray-500">Connect external services to receive signals and execute actions. {{ count(config('integrations.drivers', [])) }} integrations available.</p>
    </div>

    {{-- Connected Integrations --}}
    @if($connectedIntegrations->isNotEmpty())
        <div class="rounded-xl border border-gray-200 bg-white">
            <div class="border-b border-gray-100 px-6 py-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Connected ({{ $connectedIntegrations->count() }})</h2>
            </div>
            <div class="divide-y divide-gray-100">
                @foreach($connectedIntegrations as $integration)
                    @php
                        $driverInfo = config("integrations.drivers.{$integration->driver}", []);
                        $status = $integration->status;
                    @endphp
                    <div class="flex items-center justify-between px-6 py-4">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">{{ $driverInfo['icon'] ?? '🔌' }}</span>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="font-medium text-gray-900">{{ $integration->name }}</span>
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs {{ $status->color() }}">{{ $status->label() }}</span>
                                </div>
                                <span class="text-xs text-gray-500">{{ $driverInfo['label'] ?? ucfirst($integration->driver) }}
                                    @if($integration->last_pinged_at)
                                        &middot; Pinged {{ $integration->last_pinged_at->diffForHumans() }}
                                    @endif
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-3">
                            <a href="{{ route('integrations.show', $integration) }}"
                               class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Details</a>
                            <button wire:click="ping('{{ $integration->id }}')"
                                    class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">Ping</button>
                            <button wire:click="disconnect('{{ $integration->id }}')"
                                    wire:confirm="Disconnect this integration?"
                                    class="rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">Disconnect</button>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Available Drivers --}}
    <div class="rounded-xl border border-gray-200 bg-white">
        <div class="border-b border-gray-100 px-6 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">Available Integrations</h2>
                <input type="search" wire:model.live.debounce.200ms="search" placeholder="Search integrations..."
                    class="w-64 rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500" />
            </div>

            {{-- Category Tabs --}}
            @php
                $categoryLabels = [
                    'generic' => 'Generic',
                    'developer' => 'Developer Tools',
                    'communication' => 'Communication',
                    'project' => 'Project Management',
                    'crm' => 'CRM & Sales',
                    'support' => 'Customer Support',
                    'marketing' => 'Marketing & Email',
                    'payments' => 'Payments & E-commerce',
                    'productivity' => 'Productivity',
                    'social' => 'Social Media',
                    'automation' => 'Automation',
                    'alerting' => 'Incident & Alerting',
                    'realtime' => 'Real-time',
                    'ai' => 'AI & ML',
                ];
                $authLabels = [
                    'api_key'      => 'API Key',
                    'oauth2'       => 'OAuth 2.0',
                    'webhook_only' => 'Webhook',
                    'basic_auth'   => 'Basic Auth',
                ];
            @endphp
            <div class="mt-3 flex flex-wrap gap-2">
                <button wire:click="$set('categoryFilter', '')"
                    class="rounded-full px-3 py-1 text-xs font-medium transition {{ !$categoryFilter ? 'bg-primary-100 text-primary-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                    All ({{ count(config('integrations.drivers', [])) }})
                </button>
                @foreach($categories as $cat)
                    @php
                        $catCount = collect(config('integrations.drivers', []))->where('category', $cat)->count();
                    @endphp
                    <button wire:click="$set('categoryFilter', '{{ $cat }}')"
                        class="rounded-full px-3 py-1 text-xs font-medium transition {{ $categoryFilter === $cat ? 'bg-primary-100 text-primary-800' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' }}">
                        {{ $categoryLabels[$cat] ?? ucfirst($cat) }} ({{ $catCount }})
                    </button>
                @endforeach
            </div>
        </div>

        {{-- Grid --}}
        <div class="p-6">
            @if(empty($availableDrivers))
                <div class="py-12 text-center text-sm text-gray-400">
                    No integrations match{{ $search ? " \"{$search}\"" : '' }}{{ $categoryFilter ? " in ".($categoryLabels[$categoryFilter] ?? $categoryFilter) : '' }}.
                </div>
            @else
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    @foreach($availableDrivers as $slug => $info)
                        <div class="group flex flex-col rounded-lg border border-gray-150 p-4 transition hover:border-primary-300 hover:shadow-sm">
                            <div class="mb-2 flex items-start justify-between">
                                <div class="flex items-center gap-2.5">
                                    <span class="text-xl">{{ $info['icon'] ?? '🔌' }}</span>
                                    <div>
                                        <p class="font-medium text-gray-900">{{ $info['label'] }}</p>
                                        <div class="flex items-center gap-1.5">
                                            <span class="inline-flex rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">{{ $authLabels[$info['auth']] ?? ucfirst($info['auth']) }}</span>
                                            @if(($info['poll_frequency'] ?? 0) > 0)
                                                <span class="inline-flex rounded-full bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-600">Polling</span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </div>

                            @if(!empty($info['description']))
                                <p class="mb-3 flex-1 text-xs leading-relaxed text-gray-500">{{ $info['description'] }}</p>
                            @else
                                <div class="mb-3 flex-1"></div>
                            @endif

                            <button wire:click="openConnectForm('{{ $slug }}')"
                                    class="w-full rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-medium text-primary-700 transition hover:bg-primary-100">
                                Connect
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>
    </div>

    {{-- Connect Form Modal --}}
    @if($showConnectForm)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-gray-900/50" wire:click.self="closeConnectForm">
            <div class="mx-4 w-full max-w-md rounded-xl border border-gray-200 bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    @php $modalDriver = config("integrations.drivers.{$connectDriver}", []); @endphp
                    <div class="flex items-center gap-2">
                        <span class="text-xl">{{ $modalDriver['icon'] ?? '🔌' }}</span>
                        <h3 class="text-lg font-semibold text-gray-900">Connect {{ $modalDriver['label'] ?? ucfirst($connectDriver) }}</h3>
                    </div>
                    <button wire:click="closeConnectForm" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                @if(!empty($modalDriver['description']))
                    <p class="mb-4 text-sm text-gray-500">{{ $modalDriver['description'] }}</p>
                @endif

                <div class="space-y-4">
                    <x-form-input wire:model="connectName" label="Integration Name" type="text"
                        placeholder="e.g. Production {{ $modalDriver['label'] ?? ucfirst($connectDriver) }}" :error="$errors->first('connectName')" />

                    @php
                        $driverConfig = $availableDrivers[$connectDriver] ?? $modalDriver;
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
                            <p class="mt-1 text-blue-600">You'll be redirected to {{ $modalDriver['label'] ?? ucfirst($connectDriver) }} to authorize access.</p>
                        </div>
                    @elseif(($driverConfig['auth'] ?? '') === 'webhook_only')
                        <div class="rounded-lg border border-gray-100 bg-gray-50 p-4 text-sm text-gray-600">
                            <p class="font-medium text-gray-700">No credentials required</p>
                            <p class="mt-1">This integration receives data via webhook. After connecting, you'll get a unique URL.</p>
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
                            Continue with {{ $modalDriver['label'] ?? ucfirst($connectDriver) }} →
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
