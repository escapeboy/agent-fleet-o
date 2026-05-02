<div>
    <form wire:submit="save" class="mx-auto max-w-2xl space-y-6">
        <x-form-input wire:model="name" label="Name" required placeholder="e.g. Web Research Kit" />

        <x-form-textarea wire:model="description" label="Description" placeholder="What does this toolset do?" />

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
            <p class="mt-1 text-xs text-gray-400">{{ count($selectedToolIds) }} tool(s) selected</p>
        </div>

        <x-form-input wire:model="tagsInput" label="Tags" placeholder="web, search, analysis (comma-separated)" />

        <div class="flex gap-3">
            <button type="submit"
                class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Create Toolset
            </button>
            <a href="{{ route('toolsets.index') }}"
                class="rounded-lg border border-gray-300 px-5 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
        </div>
    </form>
</div>
