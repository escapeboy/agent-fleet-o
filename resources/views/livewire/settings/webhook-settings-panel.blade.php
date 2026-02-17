<div>
    <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-semibold text-(--color-on-surface)">Webhook Endpoints</h3>
        <button wire:click="openForm" class="rounded-lg bg-blue-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-blue-700">
            Add Endpoint
        </button>
    </div>

    {{-- Endpoint list --}}
    @if($endpoints->isEmpty())
        <p class="text-sm text-(--color-on-surface-muted)">No webhook endpoints configured. Add one to receive notifications for experiment and project events.</p>
    @else
        <div class="space-y-3">
            @foreach($endpoints as $endpoint)
                <div class="flex items-center justify-between rounded-lg border border-(--color-theme-border) p-3">
                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="font-medium text-(--color-on-surface)">{{ $endpoint->name }}</span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $endpoint->is_active ? 'bg-green-100 text-green-700' : 'bg-(--color-surface-alt) text-(--color-on-surface-muted)' }}">
                                {{ $endpoint->is_active ? 'Active' : 'Inactive' }}
                            </span>
                        </div>
                        <p class="mt-0.5 truncate text-sm text-(--color-on-surface-muted)">{{ $endpoint->url }}</p>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach($endpoint->events ?? [] as $event)
                                <span class="inline-flex items-center rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-700">{{ $event }}</span>
                            @endforeach
                        </div>
                    </div>
                    <div class="ml-4 flex items-center gap-2">
                        <button wire:click="toggleActive('{{ $endpoint->id }}')" class="text-sm text-(--color-on-surface-muted) hover:text-(--color-on-surface)">
                            {{ $endpoint->is_active ? 'Disable' : 'Enable' }}
                        </button>
                        <button wire:click="openForm('{{ $endpoint->id }}')" class="text-sm text-blue-600 hover:text-blue-700">Edit</button>
                        <button wire:click="delete('{{ $endpoint->id }}')" wire:confirm="Delete this webhook endpoint?" class="text-sm text-red-600 hover:text-red-700">Delete</button>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Add/Edit form --}}
    @if($showForm)
        <div class="mt-4 rounded-lg border border-blue-200 bg-blue-50 p-4">
            <h4 class="mb-3 font-medium text-(--color-on-surface)">{{ $editingId ? 'Edit Endpoint' : 'New Endpoint' }}</h4>
            <div class="space-y-3">
                <x-form-input wire:model="name" label="Name" placeholder="My N8N Webhook" :error="$errors->first('name')" />
                <x-form-input wire:model="url" label="URL" placeholder="https://n8n.example.com/webhook/..." :error="$errors->first('url')" />
                <x-form-input wire:model="secret" label="Secret (HMAC-SHA256)" type="password" placeholder="{{ $editingId ? 'Leave blank to keep current' : 'Auto-generated if blank' }}" />

                <div>
                    <label class="mb-1.5 block text-sm font-medium text-(--color-on-surface)">Events</label>
                    <div class="grid grid-cols-2 gap-2">
                        @foreach($availableEvents as $event)
                            <label class="flex items-center gap-2 text-sm">
                                <input type="checkbox" wire:model="selectedEvents" value="{{ $event->value }}" class="rounded border-(--color-input-border) text-blue-600">
                                {{ $event->label() }}
                            </label>
                        @endforeach
                    </div>
                    @error('selectedEvents') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <x-form-input wire:model.number="maxRetries" label="Max Retries" type="number" min="0" max="10" />

                <div class="flex gap-2">
                    <button wire:click="save" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        {{ $editingId ? 'Update' : 'Create' }}
                    </button>
                    <button wire:click="$set('showForm', false)" class="rounded-lg border border-(--color-theme-border-strong) px-4 py-2 text-sm font-medium text-(--color-on-surface) hover:bg-(--color-surface-alt)">
                        Cancel
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
