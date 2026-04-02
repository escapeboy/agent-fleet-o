<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <a href="{{ route('notifications.preferences') }}" wire:navigate
            class="ml-auto flex items-center gap-1 text-sm text-gray-500 hover:text-gray-700">
            <i class="fa-solid fa-gear text-base"></i>
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
                            <i class="fa-solid fa-check text-base"></i>
                        </button>
                    @endif
                    <button wire:click="deleteNotification('{{ $notification->id }}')"
                        class="rounded p-1 text-gray-400 hover:bg-red-50 hover:text-red-500" title="Delete">
                        <i class="fa-solid fa-xmark text-base"></i>
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
