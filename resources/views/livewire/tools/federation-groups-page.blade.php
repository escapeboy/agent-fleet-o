<div>
    {{-- Flash message --}}
    @if(session()->has('success'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('success') }}
        </div>
    @endif

    {{-- Header actions --}}
    <div class="mb-6 flex items-center justify-between">
        <p class="text-sm text-gray-500">Named subsets of tools that agents can be locked to instead of the full team pool.</p>
        <button wire:click="$set('showCreateForm', true)"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Group
        </button>
    </div>

    {{-- Create form --}}
    @if($showCreateForm)
        <div class="mb-6 rounded-xl border border-primary-200 bg-white p-6">
            <h3 class="mb-4 text-base font-semibold text-gray-900">Create Federation Group</h3>
            <div class="space-y-4">
                <x-form-input wire:model="name" label="Name" type="text" placeholder="e.g. Web Research Stack"
                    :error="$errors->first('name')" />

                <x-form-textarea wire:model="description" label="Description (optional)" rows="2"
                    placeholder="What tools are included and when to use this group..." />

                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">Select Tools</label>
                    @if($availableTools->isNotEmpty())
                        <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                            @foreach($availableTools as $tool)
                                <button type="button"
                                    wire:click="toggleTool('{{ $tool->id }}')"
                                    class="flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition
                                        {{ in_array($tool->id, $selectedToolIds) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                                    <div class="flex h-5 w-5 shrink-0 items-center justify-center rounded border
                                        {{ in_array($tool->id, $selectedToolIds) ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-300' }}">
                                        @if(in_array($tool->id, $selectedToolIds))
                                            <i class="fa-solid fa-check text-xs"></i>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $tool->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $tool->type->label() }}</div>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No active tools available.</p>
                    @endif
                </div>

                <div class="flex items-center justify-end gap-3 border-t border-gray-200 pt-4">
                    <button wire:click="$set('showCreateForm', false)"
                        class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                        Cancel
                    </button>
                    <button wire:click="create"
                        class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                        Create Group
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- Groups list --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        @forelse($groups as $group)
            <div class="flex items-start justify-between px-6 py-4 {{ !$loop->last ? 'border-b border-gray-100' : '' }}">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-medium text-gray-900">{{ $group->name }}</span>
                        @if(!$group->is_active)
                            <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-500">Inactive</span>
                        @endif
                    </div>
                    @if($group->description)
                        <p class="mt-0.5 text-sm text-gray-500">{{ $group->description }}</p>
                    @endif
                    <p class="mt-1 text-xs text-gray-400">{{ count($group->tool_ids ?? []) }} tool(s) &middot; Created {{ $group->created_at->diffForHumans() }}</p>
                </div>
                <button wire:click="delete('{{ $group->id }}')"
                    wire:confirm="Delete '{{ $group->name }}'? Agents using this group will fall back to all team tools."
                    class="ml-4 rounded-lg border border-red-200 px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
                    Delete
                </button>
            </div>
        @empty
            <div class="px-6 py-12 text-center">
                <i class="fa-solid fa-puzzle-piece mx-auto mb-3 text-4xl text-gray-300"></i>
                <p class="text-sm font-medium text-gray-600">No federation groups yet</p>
                <p class="mt-1 text-xs text-gray-400">Create a group to give agents access to a curated subset of tools.</p>
            </div>
        @endforelse
    </div>
</div>
