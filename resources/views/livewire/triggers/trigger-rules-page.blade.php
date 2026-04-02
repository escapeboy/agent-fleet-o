<div>
    {{-- Flash messages --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif
    @if(session()->has('error'))
        <div class="mb-4 rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('error') }}</div>
    @endif

    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search trigger rules...">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 text-base -translate-y-1/2 text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <a href="{{ route('triggers.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Rule
        </a>
    </div>

    {{-- Edit Modal --}}
    @if($editingRuleId)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        x-data x-on:keydown.escape.window="$wire.cancelEdit()">
        <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Edit Trigger Rule</h3>

            <div class="space-y-4">
                <x-form-input wire:model="editName" label="Name" :error="$errors->first('editName')" />

                <x-form-select wire:model="editSourceType" label="Source Type">
                    @foreach($availableSourceTypes as $type)
                        <option value="{{ $type }}">{{ $type === '*' ? 'Any source' : ucfirst(str_replace('_', ' ', $type)) }}</option>
                    @endforeach
                </x-form-select>

                <x-form-select wire:model="editProjectId" label="Project">
                    <option value="">No project</option>
                    @foreach($projects as $project)
                        <option value="{{ $project->id }}">{{ $project->title }}</option>
                    @endforeach
                </x-form-select>

                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model="editCooldownSeconds" label="Cooldown (seconds)" type="number" min="0" />
                    <x-form-input wire:model="editMaxConcurrent" label="Max Concurrent" type="number" min="-1" max="10" />
                </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
                <button type="button" wire:click="cancelEdit"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" wire:click="saveEdit"
                    class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save
                </button>
            </div>
        </div>
    </div>
    @endif

    {{-- Empty state --}}
    @if($rules->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-16">
            <i class="fa-solid fa-bolt mb-4 text-5xl text-gray-400"></i>
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
                                    <button wire:click="testTrigger('{{ $rule->id }}')"
                                        wire:confirm="Fire this trigger with the latest matching signal?"
                                        class="rounded px-2.5 py-1 text-xs font-medium text-primary-600 hover:bg-primary-50"
                                        title="Test with latest signal">
                                        Test
                                    </button>
                                    <button wire:click="startEdit('{{ $rule->id }}')"
                                        class="rounded px-2.5 py-1 text-xs font-medium text-gray-600 hover:bg-gray-100">
                                        Edit
                                    </button>
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
