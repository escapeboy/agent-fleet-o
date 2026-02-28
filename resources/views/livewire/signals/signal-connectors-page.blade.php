<div class="space-y-8">
    {{-- Page header --}}
    <div>
        <p class="mt-1 text-sm text-(--color-on-surface-muted)">
            Configure inbound connectors that deliver signals to FleetQ. Signals trigger agents, projects, and automation rules.
        </p>
    </div>

    {{-- ═══ Section 1: Webhook Sources ═══ --}}
    <div>
        <div class="mb-4">
            <h2 class="text-base font-semibold text-(--color-on-surface)">Webhook Sources</h2>
            <p class="text-sm text-(--color-on-surface-muted)">Push-based connectors. Configure the webhook URL in the external service once.</p>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($cards as $driver => $card)
                @php
                    $dotColor = match($card['status']) {
                        'active'                      => 'bg-green-500',
                        'stale', 'configured',
                        'unsecured'                   => 'bg-amber-400',
                        default                       => 'bg-gray-300',
                    };
                    $statusLabel = match($card['status']) {
                        'active'       => 'Active',
                        'stale'        => 'Stale',
                        'configured'   => 'Configured',
                        'unsecured'    => 'Unsecured',
                        default        => 'Not configured',
                    };
                    $statusTextColor = match($card['status']) {
                        'active'                            => 'text-green-600',
                        'stale', 'configured', 'unsecured'  => 'text-amber-600',
                        default                             => 'text-(--color-on-surface-muted)',
                    };
                @endphp
                <div class="group flex flex-col rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-5 transition hover:border-(--color-theme-border-strong) hover:shadow-sm">
                    {{-- Header --}}
                    <div class="flex items-start justify-between">
                        <div class="flex items-center gap-3">
                            <div class="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg {{ $card['secret_configured'] ? 'bg-primary-50 text-primary-700' : 'bg-(--color-surface-alt) text-(--color-on-surface-muted)' }}">
                                <span class="text-sm font-bold">{{ strtoupper(substr($card['label'], 0, 2)) }}</span>
                            </div>
                            <div>
                                <p class="text-sm font-semibold text-(--color-on-surface)">{{ $card['label'] }}</p>
                                <p class="text-xs text-(--color-on-surface-muted)">{{ $card['category'] }}</p>
                            </div>
                        </div>
                        <div class="flex items-center gap-1.5 pt-0.5">
                            @if($card['status'] === 'unsecured')
                                <span class="text-xs text-amber-600">⚠</span>
                            @else
                                <span class="h-2 w-2 rounded-full {{ $dotColor }}"></span>
                            @endif
                            <span class="text-xs {{ $statusTextColor }}">{{ $statusLabel }}</span>
                        </div>
                    </div>

                    {{-- Signal stats --}}
                    <div class="mt-4 flex-1 space-y-1">
                        @if($card['last_received_at'])
                            <p class="text-xs text-(--color-on-surface-muted)">
                                Last signal: <span class="text-(--color-on-surface)">{{ $card['last_received_at']->diffForHumans() }}</span>
                            </p>
                            <p class="text-xs text-(--color-on-surface-muted)">
                                {{ $card['total_signals'] }} {{ Str::plural('signal', $card['total_signals']) }} in 30 days
                            </p>
                        @else
                            <p class="text-xs italic text-(--color-on-surface-muted)">No signals received yet</p>
                        @endif
                    </div>

                    {{-- CTA --}}
                    <button
                        wire:click="openSetupPanel('{{ $driver }}')"
                        class="mt-4 w-full rounded-lg border border-(--color-theme-border-strong) px-3 py-1.5 text-xs font-medium text-(--color-on-surface) transition hover:bg-(--color-surface-alt) group-hover:border-primary-300 group-hover:text-primary-600"
                    >
                        {{ $card['secret_configured'] ? 'Manage' : 'Set Up' }}
                    </button>
                </div>
            @endforeach
        </div>
    </div>

    {{-- ═══ Section 2: HTTP Monitors ═══ --}}
    <div>
        <div class="mb-4 flex items-center justify-between">
            <div>
                <h2 class="text-base font-semibold text-(--color-on-surface)">HTTP Monitors</h2>
                <p class="text-sm text-(--color-on-surface-muted)">Monitor URLs for availability and content changes. Checks every 5 minutes.</p>
            </div>
            @unless($showAddMonitor)
                <button wire:click="$set('showAddMonitor', true)"
                    class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                    Add URL
                </button>
            @endunless
        </div>

        @if($httpMonitors->isEmpty() && !$showAddMonitor)
            <div class="rounded-xl border border-dashed border-(--color-theme-border) py-10 text-center">
                <p class="text-sm text-(--color-on-surface-muted)">No URLs monitored yet.</p>
                <p class="text-xs text-(--color-on-surface-muted)">Add a URL to monitor for availability or content changes.</p>
            </div>
        @elseif($httpMonitors->isNotEmpty())
            <div class="overflow-hidden rounded-xl border border-(--color-theme-border)">
                <table class="min-w-full divide-y divide-(--color-theme-border)">
                    <thead class="bg-(--color-surface-alt)">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-(--color-on-surface-muted)">URL</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-(--color-on-surface-muted)">Type</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-(--color-on-surface-muted)">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-(--color-on-surface-muted)">Last Check</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-(--color-theme-border)">
                        @foreach ($httpMonitors as $monitor)
                            @php
                                $lastStatus = $monitor->config['last_status'] ?? null;
                                $failures   = $monitor->config['consecutive_failures'] ?? 0;
                                $statusColor = match(true) {
                                    $lastStatus === null                              => 'text-(--color-on-surface-muted)',
                                    $lastStatus >= 200 && $lastStatus < 300          => 'text-green-600',
                                    $lastStatus >= 300 && $lastStatus < 400          => 'text-amber-600',
                                    default                                          => 'text-red-600',
                                };
                                $dotColor = match(true) {
                                    $failures > 0                                    => 'bg-red-500',
                                    $lastStatus !== null && $lastStatus >= 200
                                        && $lastStatus < 300                         => 'bg-green-500',
                                    $lastStatus === null                             => 'bg-gray-300',
                                    default                                          => 'bg-amber-400',
                                };
                            @endphp
                            <tr class="transition hover:bg-(--color-surface-alt)/50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-2">
                                        <span class="h-2 w-2 shrink-0 rounded-full {{ $dotColor }}"></span>
                                        <span class="max-w-xs truncate text-sm text-(--color-on-surface)"
                                              title="{{ $monitor->config['url'] ?? '' }}">
                                            {{ $monitor->name ?: ($monitor->config['url'] ?? '') }}
                                        </span>
                                    </div>
                                    @if($failures > 0)
                                        <p class="ml-4 text-xs text-red-600">{{ $failures }} consecutive {{ Str::plural('failure', $failures) }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full bg-(--color-surface-alt) px-2 py-0.5 text-xs font-medium text-(--color-on-surface)">
                                        {{ ucfirst(str_replace('_', ' ', $monitor->config['monitor_type'] ?? 'availability')) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="font-mono text-sm {{ $statusColor }}">
                                        {{ $lastStatus ?? '—' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-(--color-on-surface-muted)">
                                    {{ $monitor->last_success_at?->diffForHumans() ?? 'Never' }}
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <button
                                        wire:click="removeMonitor('{{ $monitor->id }}')"
                                        wire:confirm="Remove monitor for {{ $monitor->config['url'] ?? 'this URL' }}?"
                                        class="text-xs text-red-600 hover:text-red-800">
                                        Remove
                                    </button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif

        {{-- Add Monitor Form --}}
        @if($showAddMonitor)
            <div class="mt-4 space-y-4 rounded-xl border border-primary-200 bg-primary-50/50 p-5">
                <h3 class="text-sm font-semibold text-(--color-on-surface)">Add URL Monitor</h3>
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-form-input wire:model="newMonitorUrl" label="URL" type="url"
                        placeholder="https://example.com"
                        :error="$errors->first('newMonitorUrl')" />
                    <x-form-input wire:model="newMonitorName" label="Name (optional)" type="text"
                        placeholder="My Website" />
                </div>
                <x-form-select wire:model="newMonitorType" label="Monitor Type">
                    <option value="availability">Availability (HTTP status code)</option>
                    <option value="content_change">Content Change (page body hash)</option>
                    <option value="both">Both</option>
                </x-form-select>
                <div class="flex gap-2">
                    <button wire:click="addMonitor"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Start Monitoring
                    </button>
                    <button wire:click="$set('showAddMonitor', false)"
                        class="rounded-lg border border-(--color-theme-border-strong) px-4 py-2 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                        Cancel
                    </button>
                </div>
            </div>
        @endif
    </div>

    {{-- ═══ Section 3: Polling Sources ═══ --}}
    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">

        {{-- RSS Feeds --}}
        <div>
            <div class="mb-4 flex items-center justify-between">
                <div>
                    <h2 class="text-base font-semibold text-(--color-on-surface)">RSS Feeds</h2>
                    <p class="text-sm text-(--color-on-surface-muted)">Polls every 15 minutes.</p>
                </div>
                @unless($showAddRss)
                    <button wire:click="$set('showAddRss', true)"
                        class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                        Add Feed
                    </button>
                @endunless
            </div>

            @if($rssFeeds->isEmpty() && !$showAddRss)
                <div class="rounded-xl border border-dashed border-(--color-theme-border) py-8 text-center">
                    <p class="text-sm text-(--color-on-surface-muted)">No RSS feeds configured.</p>
                </div>
            @endif

            <div class="space-y-2">
                @foreach($rssFeeds as $feed)
                    <div class="flex items-center justify-between rounded-lg border border-(--color-theme-border) bg-(--color-surface-raised) px-4 py-3">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-(--color-on-surface)">{{ $feed->name }}</p>
                            <p class="truncate text-xs text-(--color-on-surface-muted)" title="{{ $feed->config['url'] ?? '' }}">
                                {{ $feed->config['url'] ?? '' }}
                            </p>
                            @if($feed->last_error_message)
                                <p class="truncate text-xs text-red-600">{{ $feed->last_error_message }}</p>
                            @elseif($feed->last_success_at)
                                <p class="text-xs text-(--color-on-surface-muted)">Last polled {{ $feed->last_success_at->diffForHumans() }}</p>
                            @else
                                <p class="text-xs italic text-(--color-on-surface-muted)">Pending first poll</p>
                            @endif
                        </div>
                        <button wire:click="removeRssFeed('{{ $feed->id }}')"
                            wire:confirm="Remove feed {{ $feed->name }}?"
                            class="ml-3 shrink-0 text-xs text-red-600 hover:text-red-800">
                            Remove
                        </button>
                    </div>
                @endforeach
            </div>

            @if($showAddRss)
                <div class="mt-3 space-y-3 rounded-xl border border-primary-200 bg-primary-50/50 p-4">
                    <h3 class="text-sm font-semibold text-(--color-on-surface)">Add RSS Feed</h3>
                    <x-form-input wire:model="newRssUrl" label="Feed URL" type="url"
                        placeholder="https://example.com/feed.xml"
                        :error="$errors->first('newRssUrl')" />
                    <x-form-input wire:model="newRssName" label="Name (optional)" type="text"
                        placeholder="Example Blog" />
                    <div class="flex gap-2">
                        <button wire:click="addRssFeed"
                            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Add Feed
                        </button>
                        <button wire:click="$set('showAddRss', false)"
                            class="rounded-lg border border-(--color-theme-border-strong) px-4 py-2 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                            Cancel
                        </button>
                    </div>
                </div>
            @endif
        </div>

        {{-- IMAP --}}
        <div>
            <div class="mb-4">
                <h2 class="text-base font-semibold text-(--color-on-surface)">Email (IMAP)</h2>
                <p class="text-sm text-(--color-on-surface-muted)">Monitor an inbox for incoming messages as signals.</p>
            </div>

            @if($imapConnector)
                <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-5">
                    <div class="flex items-center gap-2">
                        <span class="h-2 w-2 rounded-full {{ $imapConnector->last_error_at && $imapConnector->last_error_at->gt($imapConnector->last_success_at ?? now()->subYear()) ? 'bg-red-500' : 'bg-green-500' }}"></span>
                        <span class="text-sm font-medium text-(--color-on-surface)">{{ $imapConnector->name }}</span>
                    </div>
                    <div class="mt-2 space-y-1">
                        <p class="text-xs text-(--color-on-surface-muted)">Host: {{ $imapConnector->config['host'] ?? '—' }}</p>
                        <p class="text-xs text-(--color-on-surface-muted)">Folder: {{ $imapConnector->config['folder'] ?? 'INBOX' }}</p>
                        @if($imapConnector->last_success_at)
                            <p class="text-xs text-(--color-on-surface-muted)">Last polled {{ $imapConnector->last_success_at->diffForHumans() }}</p>
                        @endif
                        @if($imapConnector->last_error_message)
                            <p class="text-xs text-red-600">{{ $imapConnector->last_error_message }}</p>
                        @endif
                    </div>
                </div>
            @else
                <div class="space-y-3 rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-5">
                    <p class="text-sm text-(--color-on-surface-muted)">No IMAP connector configured.</p>
                    <p class="text-sm text-(--color-on-surface-muted)">
                        IMAP connectors require a
                        <a href="{{ route('credentials.index') }}" class="text-primary-600 underline hover:text-primary-700">Credential</a>
                        with your mail server details. Create one first, then configure the connector via the API or MCP.
                    </p>
                    <div class="space-y-1 rounded-lg bg-(--color-surface-alt) p-3 font-mono text-xs text-(--color-on-surface-muted)">
                        <p># Create a Credential first, then create the Connector via MCP:</p>
                        <p>driver: imap, config: &#123; credential_id, host, port: 993, encryption: ssl, folder: INBOX &#125;</p>
                    </div>
                </div>
            @endif
        </div>
    </div>

    {{-- ═══ Signal Protocol Connectors ═══ --}}
    <div>
        <div class="mb-4">
            <h2 class="text-base font-semibold text-(--color-on-surface)">Signal Protocol</h2>
            <p class="text-sm text-(--color-on-surface-muted)">Privacy-first messaging via <code class="font-mono text-xs">bbernhard/signal-cli-rest-api</code> Docker sidecar. Polls every minute.</p>
        </div>
        @if($signalProtocolConnectors->isNotEmpty())
            <div class="space-y-2">
                @foreach($signalProtocolConnectors as $connector)
                    <div class="flex items-center justify-between rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) px-5 py-3">
                        <div class="flex items-center gap-3">
                            <span class="h-2 w-2 rounded-full {{ $connector->last_success_at && $connector->last_success_at->gt(now()->subMinutes(5)) ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                            <div>
                                <p class="text-sm font-medium text-(--color-on-surface)">{{ $connector->name }}</p>
                                <p class="text-xs text-(--color-on-surface-muted)">{{ $connector->config['phone_number'] ?? '—' }} · {{ $connector->config['api_url'] ?? '—' }}</p>
                            </div>
                        </div>
                        @if($connector->last_error_message)
                            <span class="text-xs text-red-600 truncate max-w-xs">{{ $connector->last_error_message }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-5 space-y-2">
                <p class="text-sm text-(--color-on-surface-muted)">No Signal Protocol connector configured.</p>
                <div class="rounded-lg bg-(--color-surface-alt) p-3 font-mono text-xs text-(--color-on-surface-muted) space-y-1">
                    <p># Run the sidecar:</p>
                    <p>docker run -p 8080:8080 bbernhard/signal-cli-rest-api</p>
                    <p># Then create a Connector via MCP:</p>
                    <p>driver: signal_protocol, config: &#123; api_url, phone_number, team_id &#125;</p>
                </div>
            </div>
        @endif
    </div>

    {{-- ═══ Matrix Connectors ═══ --}}
    <div>
        <div class="mb-4">
            <h2 class="text-base font-semibold text-(--color-on-surface)">Matrix / Element</h2>
            <p class="text-sm text-(--color-on-surface-muted)">Self-hosted enterprise messaging via Matrix Client-Server API. Polls every minute.</p>
        </div>
        @if($matrixConnectors->isNotEmpty())
            <div class="space-y-2">
                @foreach($matrixConnectors as $connector)
                    <div class="flex items-center justify-between rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) px-5 py-3">
                        <div class="flex items-center gap-3">
                            <span class="h-2 w-2 rounded-full {{ $connector->last_success_at && $connector->last_success_at->gt(now()->subMinutes(5)) ? 'bg-green-500' : 'bg-gray-300' }}"></span>
                            <div>
                                <p class="text-sm font-medium text-(--color-on-surface)">{{ $connector->name }}</p>
                                <p class="text-xs text-(--color-on-surface-muted)">{{ $connector->config['homeserver_url'] ?? '—' }} · {{ $connector->config['bot_user_id'] ?? '—' }}</p>
                            </div>
                        </div>
                        @if($connector->last_error_message)
                            <span class="text-xs text-red-600 truncate max-w-xs">{{ $connector->last_error_message }}</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="rounded-xl border border-(--color-theme-border) bg-(--color-surface-raised) p-5 space-y-2">
                <p class="text-sm text-(--color-on-surface-muted)">No Matrix connector configured.</p>
                <div class="rounded-lg bg-(--color-surface-alt) p-3 font-mono text-xs text-(--color-on-surface-muted) space-y-1">
                    <p># Create a bot account on your homeserver, then create a Connector via MCP:</p>
                    <p>driver: matrix, config: &#123; homeserver_url, access_token, bot_user_id &#125;</p>
                </div>
            </div>
        @endif
    </div>

    {{-- Setup panel (sibling Livewire component) --}}
    @livewire('signals.connector-setup-panel')
</div>
