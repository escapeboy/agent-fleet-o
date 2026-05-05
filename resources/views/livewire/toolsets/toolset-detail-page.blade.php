<div>
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-semibold text-gray-900">{{ $toolset->name }}</h2>
            @if($toolset->description)
                <p class="mt-1 text-sm text-gray-500">{{ $toolset->description }}</p>
            @endif
        </div>
        <div class="flex gap-2">
            @if(!$editing)
                <button wire:click="startEdit"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Edit
                </button>
                <button wire:click="delete"
                    wire:confirm="Delete this toolset? Agents using it will lose access to these tools."
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                    Delete
                </button>
            @endif
        </div>
    </div>

    @if($editing)
        <form wire:submit="save" class="mb-8 space-y-5 rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="font-medium text-gray-900">Edit Toolset</h3>

            <x-form-input wire:model="name" label="Name" required />
            <x-form-textarea wire:model="description" label="Description" />

            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">Tools</label>
                <div class="max-h-64 overflow-y-auto rounded-lg border border-gray-300 bg-white p-2 space-y-1">
                    @foreach($availableTools as $tool)
                        <label class="flex cursor-pointer items-center gap-2 rounded px-2 py-1 hover:bg-gray-50">
                            <input type="checkbox" wire:model="selectedToolIds" value="{{ $tool->id }}"
                                class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                            <span class="text-sm text-gray-800">{{ $tool->name }}</span>
                            <span class="ml-auto text-xs text-gray-400">{{ $tool->type->value }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <x-form-input wire:model="tagsInput" label="Tags" placeholder="comma-separated" />

            <div class="flex gap-3">
                <button type="submit"
                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Save
                </button>
                <button type="button" wire:click="cancelEdit"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
            </div>
        </form>
    @endif

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-4 font-medium text-gray-900">Tools ({{ $tools->count() }})</h3>
            @if($tools->isEmpty())
                <p class="text-sm text-gray-400">No tools in this toolset yet.</p>
            @else
                <ul class="space-y-2">
                    @foreach($tools as $tool)
                        <li class="flex items-center justify-between rounded-lg bg-gray-50 px-3 py-2">
                            <span class="text-sm font-medium text-gray-800">{{ $tool->name }}</span>
                            <span class="text-xs text-gray-400">{{ $tool->type->value }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <h3 class="mb-4 font-medium text-gray-900">Agents using this ({{ $agents->count() }})</h3>
            @if($agents->isEmpty())
                <p class="text-sm text-gray-400">No agents attached to this toolset yet.</p>
            @else
                <ul class="space-y-2">
                    @foreach($agents as $agent)
                        <li class="flex items-center gap-2 rounded-lg bg-gray-50 px-3 py-2">
                            <span class="text-sm text-gray-800">{{ $agent->name }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            @if(!empty($toolset->tags))
                <div class="mt-4 flex flex-wrap gap-1">
                    @foreach($toolset->tags as $tag)
                        <span class="rounded bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $tag }}</span>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
