<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search trigger rules...">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <a href="{{ route('triggers.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Rule
        </a>
    </div>

    {{-- Empty state --}}
    @if($rules->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-16">
            <svg class="mb-4 h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 10V3L4 14h7v7l9-11h-7z"/>
            </svg>
            <p class="mb-1 text-sm font-medium text-gray-900">No trigger rules yet</p>
            <p class="mb-4 text-sm text-gray-500">Automatically run projects when signals arrive from external sources.</p>
            <a href="{{ route('triggers.create') }}" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Create your first trigger rule
            </a>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Project</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Triggered</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Total</th>
                        <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($rules as $rule)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <p class="font-medium text-gray-900">{{ $rule->name }}</p>
                                @if($rule->conditions)
                                    <p class="mt-0.5 text-xs text-gray-400">{{ count($rule->conditions) }} condition(s)</p>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-0.5 text-xs font-medium text-blue-800">
                                    {{ $rule->source_type === '*' ? 'Any source' : $rule->source_type }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-600">
                                {{ $rule->project?->title ?? '—' }}
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    {{ $rule->status->isActive() ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                    {{ $rule->status->label() }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $rule->last_triggered_at?->diffForHumans() ?? 'Never' }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ number_format($rule->total_triggers) }}
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex justify-end gap-2">
                                    <button wire:click="toggleStatus('{{ $rule->id }}')"
                                        class="rounded px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100">
                                        {{ $rule->status->isActive() ? 'Pause' : 'Activate' }}
                                    </button>
                                    <button wire:click="delete('{{ $rule->id }}')"
                                        wire:confirm="Delete this trigger rule?"
                                        class="rounded px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $rules->links() }}
        </div>
    @endif
</div>
