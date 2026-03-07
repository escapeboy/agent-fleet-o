<div>
    @if(session('message'))
        <div class="mb-4 rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex flex-1 gap-3">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search templates..."
                class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"
            />
            <select wire:model.live="statusFilter" class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">All statuses</option>
                @foreach($statuses as $s)
                    <option value="{{ $s->value }}">{{ $s->label() }}</option>
                @endforeach
            </select>
        </div>
        <button
            wire:click="openCreateModal"
            class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700"
        >
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
            New Template
        </button>
    </div>

    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="w-full text-sm">
            <thead class="border-b border-gray-200 bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-medium text-gray-500">Name</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500">Subject</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500">Status</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500">Visibility</th>
                    <th class="px-4 py-3 text-left font-medium text-gray-500">Updated</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($templates as $template)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3 font-medium text-gray-900">
                            <a href="{{ route('email.templates.edit', $template) }}" class="hover:text-primary-600">
                                {{ $template->name }}
                            </a>
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $template->subject ?? '—' }}</td>
                        <td class="px-4 py-3">
                            @php $color = $template->status->color(); @endphp
                            <span class="inline-flex items-center rounded-full bg-{{ $color }}-50 px-2 py-0.5 text-xs font-medium text-{{ $color }}-700">
                                {{ $template->status->label() }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-gray-500">{{ $template->visibility->label() }}</td>
                        <td class="px-4 py-3 text-gray-400 text-xs">{{ $template->updated_at->diffForHumans() }}</td>
                        <td class="px-4 py-3 text-right">
                            <div class="flex items-center justify-end gap-2">
                                @if($template->html_cache)
                                    <a href="{{ route('email.templates.preview', $template) }}" target="_blank"
                                       class="text-xs text-gray-400 hover:text-gray-600">Preview</a>
                                @endif
                                <a href="{{ route('email.templates.edit', $template) }}"
                                   class="text-xs text-primary-600 hover:text-primary-700">Edit</a>
                                <button
                                    wire:click="delete('{{ $template->id }}')"
                                    wire:confirm="Delete '{{ $template->name }}'? This cannot be undone."
                                    class="text-xs text-red-400 hover:text-red-600"
                                >Delete</button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-12 text-center text-gray-400">
                            No templates yet. Create your first template to get started.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if($templates->hasPages())
        <div class="mt-4">{{ $templates->links() }}</div>
    @endif

    {{-- Create modal --}}
    @if($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50">
            <div class="w-full max-w-md rounded-xl bg-white p-6 shadow-xl">
                <h2 class="mb-4 text-lg font-semibold text-gray-900">New Email Template</h2>
                <x-form-input
                    wire:model="newName"
                    label="Template name"
                    placeholder="e.g. Welcome Email"
                    autofocus
                />
                @error('newName') <p class="mt-1 text-xs text-red-500">{{ $message }}</p> @enderror
                <div class="mt-6 flex justify-end gap-3">
                    <button wire:click="$set('showCreateModal', false)" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="create" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Create & Open Editor
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>
