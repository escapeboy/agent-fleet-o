<div>
    {{-- Header toolbar --}}
    <div class="mb-6 flex items-center justify-between">
        <p class="text-sm text-gray-600">
            Per-source webhook subscriptions linked to OAuth / API-key integrations.
            Each subscription gets a unique URL and HMAC secret.
        </p>

        @if($integrations->isNotEmpty())
            <button wire:click="$set('showForm', true)"
                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                </svg>
                Add Subscription
            </button>
        @endif
    </div>

    {{-- No integrations callout --}}
    @if($integrations->isEmpty() && $subscriptions->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center">
            <svg class="mx-auto mb-4 h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
            </svg>
            <p class="mb-1 text-sm font-medium text-gray-900">No supported integrations found</p>
            <p class="mb-4 text-sm text-gray-500">Connect a GitHub integration first, then come back to create subscriptions.</p>
            <a href="{{ route('integrations.index') }}"
                class="inline-flex items-center rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Go to Integrations
            </a>
        </div>
    @endif

    {{-- Add form slide-in --}}
    @if($showForm)
        <div class="mb-6 rounded-xl border border-gray-200 bg-white p-6 shadow-sm">
            <h3 class="mb-4 text-sm font-semibold text-gray-900">New Subscription</h3>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                {{-- Integration picker --}}
                <div class="sm:col-span-2">
                    <x-form-select wire:model.live="integrationId" label="Integration" required>
                        <option value="">Select integration…</option>
                        @foreach($integrations as $integration)
                            <option value="{{ $integration->id }}">
                                {{ $integration->name }} ({{ ucfirst($integration->driver) }})
                            </option>
                        @endforeach
                    </x-form-select>
                    @error('integrationId') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                {{-- Display name --}}
                <x-form-input
                    wire:model="name"
                    label="Name"
                    placeholder="e.g. my-org/backend"
                    hint="A friendly label for this subscription."
                />

                {{-- GitHub fields --}}
                @if($selectedDriver === 'github')
                    <x-form-input
                        wire:model="repo"
                        label="Repository"
                        placeholder="owner/repo"
                        hint="GitHub repository in owner/repo format."
                    />

                    <x-form-input
                        wire:model="filterBranches"
                        label="Branch filter"
                        placeholder="main, develop"
                        hint="Comma-separated branch names (push events only). Leave blank for all."
                    />

                    <x-form-input
                        wire:model="filterEventTypes"
                        label="Event types"
                        placeholder="issues, push, pull_request"
                        hint="Comma-separated event types. Leave blank for all."
                    />
                @endif

                {{-- Linear fields --}}
                @if($selectedDriver === 'linear')
                    <x-form-input
                        wire:model="linearTeamId"
                        label="Linear Team ID"
                        placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                        hint="Leave blank to receive events from all teams on this account."
                    />

                    <x-form-input
                        wire:model="linearResourceTypes"
                        label="Resource types"
                        placeholder="Issue, Comment"
                        hint="Comma-separated Linear resource types. Leave blank for Issue + Comment."
                    />

                    <x-form-input
                        wire:model="filterEventTypes"
                        label="Action filter"
                        placeholder="create, update"
                        hint="Comma-separated actions (create, update, remove). Leave blank for all."
                    />
                @endif

                {{-- Jira fields --}}
                @if($selectedDriver === 'jira')
                    <x-form-input
                        wire:model="jiraProjectKey"
                        label="Project Key"
                        placeholder="ENG"
                        hint="Jira project key (e.g. ENG). Leave blank to receive events from all projects."
                    />

                    <x-form-input
                        wire:model="filterEventTypes"
                        label="Event types"
                        placeholder="jira:issue_created, jira:issue_updated"
                        hint="Comma-separated Jira event types. Leave blank for issue_created + issue_updated + comments."
                    />
                @endif
            </div>

            @error('repo') <p class="mt-2 text-xs text-red-600">{{ $message }}</p> @enderror

            <div class="mt-4 flex items-center gap-3">
                <button wire:click="save"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    <span wire:loading.remove>Save &amp; Register Webhook</span>
                    <span wire:loading>Saving…</span>
                </button>
                <button wire:click="$set('showForm', false)"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Subscriptions table --}}
    @if($subscriptions->isNotEmpty())
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Integration</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Webhook</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Signals</th>
                        <th class="px-5 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last signal</th>
                        <th class="px-5 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($subscriptions as $sub)
                        <tr wire:key="{{ $sub->id }}" class="{{ $sub->is_active ? '' : 'bg-gray-50 opacity-60' }}">
                            <td class="px-5 py-3">
                                <p class="text-sm font-medium text-gray-900">{{ $sub->name }}</p>
                                <p class="text-xs text-gray-500">{{ $sub->filter_config['repo'] ?? '—' }}</p>
                            </td>
                            <td class="px-5 py-3">
                                <span class="text-sm text-gray-700">{{ $sub->integration?->name ?? '—' }}</span>
                                <span class="ml-1 inline-flex items-center rounded bg-gray-100 px-1.5 py-0.5 text-xs font-medium text-gray-600">
                                    {{ ucfirst($sub->driver) }}
                                </span>
                            </td>
                            <td class="px-5 py-3">
                                @php
                                    $statusColors = [
                                        'registered' => 'bg-green-100 text-green-700',
                                        'pending'    => 'bg-yellow-100 text-yellow-700',
                                        'failed'     => 'bg-red-100 text-red-700',
                                        'manual'     => 'bg-blue-100 text-blue-700',
                                    ];
                                    $statusColor = $statusColors[$sub->webhook_status] ?? 'bg-gray-100 text-gray-700';
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor }}">
                                    {{ ucfirst($sub->webhook_status ?? 'unknown') }}
                                </span>
                                @if($sub->isWebhookExpiringSoon())
                                    <span class="ml-1 text-xs text-orange-500">Expiring soon</span>
                                @endif
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-700">
                                {{ number_format($sub->signal_count) }}
                            </td>
                            <td class="px-5 py-3 text-sm text-gray-500">
                                {{ $sub->last_signal_at ? $sub->last_signal_at->diffForHumans() : '—' }}
                            </td>
                            <td class="px-5 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    {{-- Webhook URL copy --}}
                                    <button
                                        type="button"
                                        x-data="{ copied: false }"
                                        @click="
                                            navigator.clipboard.writeText('{{ $sub->webhookUrl() }}');
                                            copied = true;
                                            setTimeout(() => copied = false, 1500);
                                        "
                                        class="rounded p-1 text-gray-400 hover:text-gray-700"
                                        title="Copy webhook URL">
                                        <svg x-show="!copied" class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/>
                                        </svg>
                                        <svg x-show="copied" class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                                        </svg>
                                    </button>

                                    {{-- Toggle active --}}
                                    <button wire:click="toggleActive('{{ $sub->id }}')"
                                        class="rounded p-1 text-gray-400 hover:text-gray-700"
                                        title="{{ $sub->is_active ? 'Disable' : 'Enable' }}">
                                        @if($sub->is_active)
                                            <svg class="h-4 w-4 text-green-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9v6m4-6v6m7-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                        @else
                                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/>
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                            </svg>
                                        @endif
                                    </button>

                                    {{-- Delete --}}
                                    <button wire:click="delete('{{ $sub->id }}')"
                                        wire:confirm="Delete this subscription? The webhook will be deregistered at the provider."
                                        class="rounded p-1 text-gray-400 hover:text-red-600"
                                        title="Delete">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @elseif(!$showForm && $integrations->isNotEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-16">
            <svg class="mb-4 h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                    d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1"/>
            </svg>
            <p class="mb-1 text-sm font-medium text-gray-900">No subscriptions yet</p>
            <p class="mb-4 text-sm text-gray-500">Add a subscription to start receiving GitHub events for a specific repository.</p>
            <button wire:click="$set('showForm', true)"
                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Add Subscription
            </button>
        </div>
    @endif
</div>
