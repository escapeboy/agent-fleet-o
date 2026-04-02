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
                <i class="fa-solid fa-plus text-base"></i>
                Add Subscription
            </button>
        @endif
    </div>

    {{-- No integrations callout --}}
    @if($integrations->isEmpty() && $subscriptions->isEmpty())
        <div class="rounded-xl border border-dashed border-gray-300 bg-white p-10 text-center">
            <i class="fa-solid fa-link mx-auto mb-4 text-4xl text-gray-400"></i>
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
                                        <i x-show="!copied" class="fa-regular fa-clipboard text-base"></i>
                                        <i x-show="copied" class="fa-solid fa-check text-base text-green-500"></i>
                                    </button>

                                    {{-- Toggle active --}}
                                    <button wire:click="toggleActive('{{ $sub->id }}')"
                                        class="rounded p-1 text-gray-400 hover:text-gray-700"
                                        title="{{ $sub->is_active ? 'Disable' : 'Enable' }}">
                                        @if($sub->is_active)
                                            <i class="fa-solid fa-circle-pause text-base text-green-500"></i>
                                        @else
                                            <i class="fa-solid fa-circle-play text-base"></i>
                                        @endif
                                    </button>

                                    {{-- Delete --}}
                                    <button wire:click="delete('{{ $sub->id }}')"
                                        wire:confirm="Delete this subscription? The webhook will be deregistered at the provider."
                                        class="rounded p-1 text-gray-400 hover:text-red-600"
                                        title="Delete">
                                        <i class="fa-solid fa-trash text-base"></i>
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
            <i class="fa-solid fa-link mb-4 text-4xl text-gray-400"></i>
            <p class="mb-1 text-sm font-medium text-gray-900">No subscriptions yet</p>
            <p class="mb-4 text-sm text-gray-500">Add a subscription to start receiving GitHub events for a specific repository.</p>
            <button wire:click="$set('showForm', true)"
                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Add Subscription
            </button>
        </div>
    @endif
</div>
