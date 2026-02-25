<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <a href="{{ route('notifications.preferences') }}" wire:navigate
            class="ml-auto flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/>
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>
            </svg>
            Preferences
        </a>
        <div class="flex items-center gap-2">
            <label class="flex items-center gap-2 text-sm text-gray-700">
                <input type="checkbox" wire:model.live="unreadOnly" class="rounded border-gray-300">
                Unread only
            </label>
        </div>

        <x-form-select wire:model.live="typeFilter">
            <option value="">All Types</option>
            @foreach($types as $type)
                <option value="{{ $type }}">{{ ucwords(str_replace('_', ' ', $type)) }}</option>
            @endforeach
        </x-form-select>

        @if($unreadCount > 0)
            <button wire:click="markAllRead"
                class="rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-600 hover:bg-gray-50">
                Mark all as read ({{ $unreadCount }})
            </button>
        @endif
    </div>

    {{-- List --}}
    <div class="space-y-2">
        @forelse($notifications as $notification)
            <div class="flex items-start gap-4 rounded-xl border border-gray-200 bg-white p-4 {{ $notification->isRead() ? '' : 'border-blue-200 bg-blue-50/30' }}">
                {{-- Unread indicator --}}
                <div class="mt-1 shrink-0">
                    @if(!$notification->isRead())
                        <span class="block h-2.5 w-2.5 rounded-full bg-blue-500"></span>
                    @else
                        <span class="block h-2.5 w-2.5 rounded-full bg-gray-200"></span>
                    @endif
                </div>

                {{-- Content --}}
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <p class="text-sm font-medium text-gray-900">{{ $notification->title }}</p>
                            <p class="mt-0.5 text-sm text-gray-600">{{ $notification->body }}</p>
                            @if($notification->action_url)
                                <a href="{{ $notification->action_url }}" wire:click="markRead('{{ $notification->id }}')"
                                    class="mt-1 inline-block text-sm text-primary-600 hover:text-primary-700">
                                    View →
                                </a>
                            @endif
                        </div>
                        <div class="shrink-0 text-right">
                            <p class="text-xs text-gray-400">{{ $notification->created_at->diffForHumans() }}</p>
                            <span class="mt-1 inline-block rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-500">
                                {{ ucwords(str_replace('_', ' ', $notification->type)) }}
                            </span>
                        </div>
                    </div>
                </div>

                {{-- Actions --}}
                <div class="flex shrink-0 gap-1">
                    @if(!$notification->isRead())
                        <button wire:click="markRead('{{ $notification->id }}')"
                            class="rounded p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Mark as read">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                            </svg>
                        </button>
                    @endif
                    <button wire:click="deleteNotification('{{ $notification->id }}')"
                        class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-500" title="Delete">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>
            </div>
        @empty
            <div class="py-16 text-center">
                <p class="text-sm text-gray-500">
                    {{ $unreadOnly ? 'No unread notifications.' : 'No notifications yet.' }}
                </p>
            </div>
        @endforelse
    </div>

    @if($notifications->hasPages())
        <div class="mt-6">{{ $notifications->links() }}</div>
    @endif
</div>
