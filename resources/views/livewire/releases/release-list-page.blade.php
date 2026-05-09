<div>
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <div class="mb-6 flex items-center justify-between">
        <div>
            <p class="text-sm text-gray-500">Versioned bundles of artifacts your crews and workflows have produced.</p>
        </div>
        @unless($creating)
            <button wire:click="startCreate"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                <i class="fa-solid fa-plus mr-1"></i>New release
            </button>
        @endunless
    </div>

    @if($creating)
        <div class="mb-6 rounded-xl border border-primary-200 bg-primary-50 p-6">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-primary-700">Create release</h3>
            <div class="space-y-3">
                <x-form-input wire:model="newName" label="Name" placeholder="e.g. Q3 Marketing Site"
                    :error="$errors->first('newName')" />
                <x-form-input wire:model="newVersion" label="Version" placeholder="e.g. v1.0 or 2026.05.09"
                    :error="$errors->first('newVersion')" />
                <x-form-textarea wire:model="newNotes" label="Notes" hint="Optional"
                    :error="$errors->first('newNotes')" />
            </div>
            <div class="mt-4 flex justify-end gap-2">
                <button wire:click="cancelCreate"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                <button wire:click="create"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Create</button>
            </div>
        </div>
    @endif

    <div class="rounded-xl border border-gray-200 bg-white">
        @forelse($releases as $release)
            <a href="{{ route('releases.show', $release) }}"
                class="flex items-center justify-between border-b border-gray-100 px-6 py-4 last:border-0 hover:bg-gray-50">
                <div>
                    <div class="flex items-center gap-3">
                        <span class="font-medium text-gray-900">{{ $release->name }}</span>
                        <span class="rounded-full bg-gray-100 px-2 py-0.5 font-mono text-xs text-gray-700">{{ $release->version }}</span>
                        <span class="inline-flex items-center rounded-full bg-{{ $release->status->color() }}-100 px-2 py-0.5 text-xs font-medium text-{{ $release->status->color() }}-700">
                            {{ $release->status->label() }}
                        </span>
                    </div>
                    @if($release->notes)
                        <p class="mt-1 text-xs text-gray-500">{{ Str::limit($release->notes, 120) }}</p>
                    @endif
                </div>
                <div class="text-xs text-gray-400">
                    {{ $release->created_at?->diffForHumans() }}
                </div>
            </a>
        @empty
            <div class="px-6 py-12 text-center text-sm text-gray-400">
                No releases yet. Click "New release" to create your first versioned artifact bundle.
            </div>
        @endforelse
    </div>

    @if($releases->hasPages())
        <div class="mt-4">{{ $releases->links() }}</div>
    @endif
</div>
