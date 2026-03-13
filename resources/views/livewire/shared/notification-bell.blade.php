<div class="relative" wire:poll.30s
    x-data="{
        pushStatus: 'unknown',
        async init() {
            if (!window.AgentFleetPush) return;
            this.pushStatus = await window.AgentFleetPush.checkSubscriptionStatus();
        },
        async subscribe() {
            if (!window.AgentFleetPush) return;
            const ok = await window.AgentFleetPush.subscribeToPush($wire);
            if (ok) this.pushStatus = 'subscribed';
        },
    }"
    x-init="init()"
    x-on:fleetq:push-status-refresh.window="init()">

    {{-- Bell button --}}
    <button wire:click="$toggle('open')"
        class="relative rounded-lg p-1.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700">
        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
        </svg>
        @if($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-xs font-bold text-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    {{-- Dropdown --}}
    @if($open)
        <div class="absolute right-0 top-full z-50 mt-2 w-80 rounded-xl border border-gray-200 bg-white shadow-lg"
            wire:click.outside="$set('open', false)">
            <div class="flex items-center justify-between border-b border-gray-100 px-4 py-3">
                <span class="text-sm font-semibold text-gray-900">Notifications</span>
                <div class="flex items-center gap-2">
                    @if($unreadCount > 0)
                        <button wire:click="markAllRead" class="text-xs text-primary-600 hover:text-primary-700">
                            Mark all read
                        </button>
                    @endif
                    <a href="{{ route('notifications.index') }}" class="text-xs text-gray-500 hover:text-gray-700">
                        View all
                    </a>
                    <a href="{{ route('notifications.preferences') }}" class="text-xs text-gray-500 hover:text-gray-700">
                        Preferences
                    </a>
                </div>
            </div>

            {{-- Push notifications prompt (shown only when unsubscribed and VAPID is configured) --}}
            @if(config('webpush.vapid.public_key'))
                <div x-show="pushStatus === 'unsubscribed'"
                    class="flex items-center justify-between gap-3 border-b border-amber-100 bg-amber-50 px-4 py-2.5">
                    <div class="flex items-center gap-2 min-w-0">
                        <svg class="h-4 w-4 shrink-0 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                        </svg>
                        <span class="text-xs text-amber-800 truncate">Enable push notifications</span>
                    </div>
                    <button @click="subscribe()"
                        class="shrink-0 rounded-md bg-amber-500 px-2.5 py-1 text-xs font-medium text-white hover:bg-amber-600">
                        Enable
                    </button>
                </div>
            @endif

            <div class="max-h-96 overflow-y-auto">
                @forelse($notifications as $notification)
                    <div class="border-b border-gray-50 px-4 py-3 {{ $notification->isRead() ? 'bg-white' : 'bg-blue-50/40' }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ $notification->title }}</p>
                                <p class="mt-0.5 text-xs text-gray-500 line-clamp-2">{{ $notification->body }}</p>
                                @if($notification->action_url)
                                    <a href="{{ $notification->action_url }}" wire:click="markRead('{{ $notification->id }}')"
                                        class="mt-1 inline-block text-xs text-primary-600 hover:text-primary-700">
                                        View →
                                    </a>
                                @endif
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-1">
                                <span class="text-xs text-gray-400">{{ $notification->created_at->diffForHumans(short: true) }}</span>
                                @if(!$notification->isRead())
                                    <button wire:click="markRead('{{ $notification->id }}')"
                                        class="h-2 w-2 rounded-full bg-blue-500 hover:bg-blue-600" title="Mark as read"></button>
                                @endif
                            </div>
                        </div>
                    </div>
                @empty
                    <div class="px-4 py-8 text-center text-sm text-gray-500">
                        No notifications yet
                    </div>
                @endforelse
            </div>
        </div>
    @endif
</div>
