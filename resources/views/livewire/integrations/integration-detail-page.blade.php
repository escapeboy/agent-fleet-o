<div class="space-y-6">
    {{-- Flash Messages --}}
    @if(session()->has('message'))
        <div class="rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif
    @if(session()->has('error'))
        <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Re-authorization Warning --}}
    @if($integration->status === \App\Domain\Integration\Enums\IntegrationStatus::RequiresReauth)
        <div class="rounded-lg border border-orange-200 bg-orange-50 p-4">
            <div class="flex items-center justify-between">
                <div>
                    <p class="font-medium text-orange-800">Re-authorization Required</p>
                    <p class="mt-0.5 text-sm text-orange-700">The OAuth2 token for this integration has expired or been revoked. Please re-authorize to restore access.</p>
                </div>
                <button wire:click="reconnect"
                        class="ml-4 shrink-0 rounded-lg bg-orange-600 px-4 py-2 text-sm font-medium text-white hover:bg-orange-700">
                    Reconnect →
                </button>
            </div>
        </div>
    @endif

    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <a href="{{ route('integrations.index') }}" class="text-sm text-primary-600 hover:underline">← Integrations</a>
            <h1 class="mt-1 text-2xl font-semibold text-gray-900">{{ $integration->name }}</h1>
        </div>
        <div class="flex gap-3">
            <button wire:click="ping"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Ping
            </button>
            @can('edit-content')
                <a href="{{ route('integrations.edit', $integration) }}"
                   wire:navigate
                   class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Edit
                </a>
            @endcan
            <button wire:click="disconnect" wire:confirm="Disconnect this integration?"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                Disconnect
            </button>
        </div>
    </div>

    {{-- Identity Card --}}
    @if($account)
        <div class="rounded-lg border border-primary-200 bg-primary-50 p-6">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-xs font-semibold uppercase tracking-wide text-primary-700">Connected account</p>
                    @php
                        // Defense-in-depth: drivers fetch identity URLs from external API
                        // responses, so we only render the link when the scheme is a safe
                        // http(s) URL — never javascript:, data:, or other risky schemes.
                        $accountUrl = !empty($account['url']) && preg_match('#^https?://#i', $account['url'])
                            ? $account['url']
                            : null;
                    @endphp
                    <p class="mt-1 text-xl font-semibold text-gray-900">
                        @if($accountUrl)
                            <a href="{{ $accountUrl }}" target="_blank" rel="noopener noreferrer"
                               class="text-primary-700 hover:underline">
                                {{ $account['label'] ?? $account['identifier'] ?? 'Connected' }}
                                <span class="text-xs">↗</span>
                            </a>
                        @else
                            {{ $account['label'] ?? $account['identifier'] ?? 'Connected' }}
                        @endif
                    </p>
                    @if(!empty($account['identifier']) && ($account['label'] ?? null) !== $account['identifier'])
                        <p class="mt-1 text-xs text-gray-500 font-mono">ID: {{ $account['identifier'] }}</p>
                    @endif
                    @if(!empty($account['metadata']))
                        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-xs sm:grid-cols-3">
                            @foreach($account['metadata'] as $k => $v)
                                @if(is_scalar($v) && $v !== '' && $v !== null)
                                    <div>
                                        <dt class="text-gray-500 uppercase tracking-wide">{{ str_replace('_', ' ', $k) }}</dt>
                                        <dd class="text-gray-900 break-all">{{ (string) $v }}</dd>
                                    </div>
                                @endif
                            @endforeach
                        </dl>
                    @endif
                </div>
                @if(!empty($account['verified_at']))
                    <p class="shrink-0 text-xs text-gray-500">
                        Verified {{ \Carbon\Carbon::parse($account['verified_at'])->diffForHumans() }}
                    </p>
                @endif
            </div>
        </div>
    @endif

    {{-- Status Card --}}
    <div class="rounded-lg border border-gray-200 bg-white p-6">
        <h2 class="mb-4 text-lg font-semibold text-gray-900">Status</h2>
        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Driver</p>
                <p class="mt-1 font-medium text-gray-900">{{ $driver->label() }}</p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Status</p>
                <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs {{ $integration->status->color() }}">
                    {{ $integration->status->label() }}
                </span>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Last Ping</p>
                <p class="mt-1 text-sm text-gray-900">
                    {{ $integration->last_pinged_at ? $integration->last_pinged_at->diffForHumans() : 'Never' }}
                </p>
            </div>
            <div>
                <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Error Count</p>
                <p class="mt-1 text-sm {{ $integration->error_count > 0 ? 'text-red-600 font-medium' : 'text-gray-900' }}">
                    {{ $integration->error_count }}
                </p>
            </div>
        </div>
        @if($integration->last_ping_message)
            <p class="mt-4 text-sm text-gray-500">Last message: {{ $integration->last_ping_message }}</p>
        @endif
    </div>

    {{-- Recent Actions (audit trail) --}}
    @if($auditEntries->isNotEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-gray-900">Recent Actions</h2>
                <a href="{{ route('audit') }}?subject_type={{ urlencode(\App\Domain\Integration\Models\Integration::class) }}&subject_id={{ $integration->id }}"
                   class="text-xs text-primary-600 hover:underline">View full audit log →</a>
            </div>
            <div class="overflow-hidden rounded-lg border border-gray-100">
                <table class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                        <tr>
                            <th class="px-4 py-2 text-left">When</th>
                            <th class="px-4 py-2 text-left">Action</th>
                            <th class="px-4 py-2 text-left">Outcome</th>
                            <th class="px-4 py-2 text-left">Latency</th>
                            <th class="px-4 py-2 text-left">Details</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach($auditEntries as $entry)
                            @php
                                $props = $entry->properties ?? [];
                                $success = $props['success'] ?? null;
                                $action = $props['action'] ?? '—';
                                $latency = $props['latency_ms'] ?? null;
                                $error = $props['error'] ?? null;
                            @endphp
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-2 whitespace-nowrap text-gray-500">{{ $entry->created_at->diffForHumans() }}</td>
                                <td class="px-4 py-2 font-mono text-gray-900">{{ $action }}</td>
                                <td class="px-4 py-2">
                                    @if($success === true)
                                        <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-xs text-green-700">success</span>
                                    @elseif($success === false)
                                        <span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-xs text-red-700">failed</span>
                                    @else
                                        <span class="text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2 text-gray-500">{{ $latency !== null ? $latency.'ms' : '—' }}</td>
                                <td class="px-4 py-2 text-xs text-gray-500">
                                    @if($error)
                                        <span class="text-red-600">{{ \Illuminate\Support\Str::limit($error, 80) }}</span>
                                    @elseif(!empty($props['params_keys']))
                                        params: {{ implode(', ', $props['params_keys']) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- Webhook Routes --}}
    @if($webhookRoutes->isNotEmpty())
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Webhook Endpoints</h2>
            <div class="space-y-2">
                @foreach($webhookRoutes as $route)
                    <div class="rounded-lg bg-gray-50 p-3">
                        <p class="text-xs font-medium text-gray-500">POST</p>
                        <code class="text-sm text-gray-900">{{ url('/api/integrations/webhook/'.$route->slug) }}</code>
                        @if($route->subscribed_events)
                            <p class="mt-1 text-xs text-gray-500">Events: {{ implode(', ', $route->subscribed_events) }}</p>
                        @endif
                        <span class="mt-1 inline-flex rounded-full px-2 py-0.5 text-xs {{ $route->is_active ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">
                            {{ $route->is_active ? 'Active' : 'Inactive' }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Available Triggers --}}
    @if(!empty($triggers))
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Triggers</h2>
            <div class="space-y-2">
                @foreach($triggers as $trigger)
                    <div class="rounded-lg border border-gray-100 p-3">
                        <p class="text-sm font-medium text-gray-900">{{ $trigger->label }}</p>
                        <p class="text-xs text-gray-500">{{ $trigger->description }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Available Actions --}}
    @if(!empty($actions))
        <div class="rounded-lg border border-gray-200 bg-white p-6">
            <h2 class="mb-4 text-lg font-semibold text-gray-900">Actions</h2>
            <div class="space-y-2">
                @foreach($actions as $action)
                    <div class="rounded-lg border border-gray-100 p-3">
                        <p class="text-sm font-medium text-gray-900">{{ $action->label }}</p>
                        <p class="text-xs text-gray-500">{{ $action->description }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    {{-- Activepieces Sync Panel --}}
    @if($integration->getAttribute('driver') === 'activepieces')
        <div class="rounded-lg border border-blue-200 bg-blue-50 p-6">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-lg font-semibold text-blue-900">Pieces Sync</h2>
                    <p class="mt-1 text-sm text-blue-700">
                        Activepieces pieces are automatically synced hourly as MCP-HTTP tools.
                        Each piece becomes available for your agents to use.
                    </p>
                    <div class="mt-4 flex gap-6 text-sm">
                        <div>
                            <span class="font-medium text-blue-900">Active pieces:</span>
                            <span class="ml-1 text-blue-800">{{ $activepiecesPieceCount ?? 0 }}</span>
                        </div>
                        <div>
                            <span class="font-medium text-blue-900">Last synced:</span>
                            <span class="ml-1 text-blue-800">
                                {{ $activepiecesLastSyncedAt ? $activepiecesLastSyncedAt->diffForHumans() : 'Never' }}
                            </span>
                        </div>
                    </div>
                </div>
                <button wire:click="syncNow"
                        wire:loading.attr="disabled"
                        class="ml-4 shrink-0 rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="syncNow">Sync Now</span>
                    <span wire:loading wire:target="syncNow">Syncing…</span>
                </button>
            </div>
        </div>
    @endif
</div>
