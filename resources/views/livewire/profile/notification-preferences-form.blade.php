<div
    x-data="{
        pushStatus: $wire.entangle('pushStatus'),
        async init() {
            if (window.AgentFleetPush) {
                const s = await window.AgentFleetPush.checkSubscriptionStatus();
                this.pushStatus = s;
                $wire.setPushStatus(s);
            }
        },
        async subscribe() {
            if (!window.AgentFleetPush) return;
            const ok = await window.AgentFleetPush.subscribeToPush($wire);
            if (ok) this.pushStatus = 'subscribed';
        },
        async unsubscribe() {
            if (!window.AgentFleetPush) return;
            await window.AgentFleetPush.unsubscribeFromPush($wire);
            this.pushStatus = 'unsubscribed';
        },
    }"
    x-init="init()"
>
    @if(session()->has('notifications_saved'))
        <div class="mb-6 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            Notification preferences saved.
        </div>
    @endif

    {{-- Push permission card --}}
    <div class="mb-6 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-4 px-6 py-4">
            <div>
                <h3 class="text-sm font-semibold text-gray-900">Browser Push Notifications</h3>
                <p class="mt-1 text-sm text-gray-500">Receive instant alerts even when the app is not in focus.</p>
            </div>
            <div>
                <template x-if="pushStatus === 'unsupported'">
                    <span class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-medium text-gray-500">Not supported in this browser</span>
                </template>
                <template x-if="pushStatus === 'denied'">
                    <span class="rounded-full bg-red-100 px-3 py-1.5 text-xs font-medium text-red-600">Blocked — enable in browser settings</span>
                </template>
                <template x-if="pushStatus === 'unsubscribed'">
                    <button @click="subscribe()" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2">
                        Enable Push Notifications
                    </button>
                </template>
                <template x-if="pushStatus === 'subscribed'">
                    <div class="flex items-center gap-3">
                        <span class="rounded-full bg-green-100 px-3 py-1.5 text-xs font-medium text-green-700">✓ Active on this device</span>
                        <button @click="unsubscribe()" class="text-xs text-gray-500 hover:text-gray-700">Disable</button>
                    </div>
                </template>
                <template x-if="pushStatus === 'unknown'">
                    <span class="text-xs text-gray-400">Checking…</span>
                </template>
            </div>
        </div>
    </div>

    {{-- Preferences matrix --}}
    <form wire:submit="save">
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm">
            <div class="border-b border-gray-100 px-6 py-4">
                <h3 class="text-sm font-semibold text-gray-900">Notification Channels</h3>
                <p class="mt-1 text-sm text-gray-500">Choose how you want to be notified for each event type.</p>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-100 bg-gray-50/50">
                            <th class="w-1/2 px-6 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Event</th>
                            @foreach($channelLabels as $channel => $label)
                                <th class="px-6 py-3 text-center text-xs font-medium uppercase tracking-wide text-gray-500">{{ $label }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        @foreach($availableChannels as $type => $channels)
                            <tr class="hover:bg-gray-50/50">
                                <td class="px-6 py-3.5 text-sm text-gray-700">{{ $typeLabels[$type] ?? $type }}</td>
                                @foreach(array_keys($channelLabels) as $channel)
                                    <td class="px-6 py-3.5 text-center">
                                        @if(in_array($channel, $channels))
                                            <input
                                                type="checkbox"
                                                wire:model="preferences.{{ $type }}.{{ $channel }}"
                                                class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                                                @if($channel === 'push') x-bind:disabled="pushStatus !== 'subscribed'" @endif
                                                @if($channel === 'push') x-bind:title="pushStatus !== 'subscribed' ? 'Enable push notifications above first' : ''" @endif
                                            >
                                        @else
                                            <span class="text-gray-200" title="Not available for this event">—</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>

        <div class="mt-6 flex items-center justify-between">
            <p class="text-xs text-gray-400">Push column is only editable when push notifications are enabled on this device.</p>
            <button type="submit"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-6 py-2.5 text-sm font-medium text-white hover:bg-primary-700 focus:outline-none focus:ring-2 focus:ring-primary-500 focus:ring-offset-2 disabled:opacity-50">
                <span wire:loading.remove>Save Preferences</span>
                <span wire:loading>Saving…</span>
            </button>
        </div>
    </form>
</div>
