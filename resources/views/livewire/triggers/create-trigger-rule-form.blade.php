<div class="mx-auto max-w-2xl">
    <form wire:submit="save" class="space-y-6">

        {{-- Name --}}
        <x-form-input wire:model="name" label="Rule Name" placeholder="e.g. Sentry critical errors → Incident response" />

        {{-- Source Type --}}
        <x-form-select wire:model="source_type" label="Signal Source">
            @foreach($availableSourceTypes as $type)
                <option value="{{ $type }}">{{ $type === '*' ? 'Any source' : $type }}</option>
            @endforeach
        </x-form-select>

        {{-- Project --}}
        <x-form-select wire:model="project_id" label="Target Project (optional)">
            <option value="">— No project (monitor only) —</option>
            @foreach($projects as $project)
                <option value="{{ $project->id }}">{{ $project->title }}</option>
            @endforeach
        </x-form-select>

        {{-- Conditions --}}
        <div>
            <div class="mb-2 flex items-center justify-between">
                <label class="block text-sm font-medium text-gray-700">Conditions (optional)</label>
                <button type="button" wire:click="addConditionRow"
                    class="text-sm text-primary-600 hover:text-primary-700">+ Add condition</button>
            </div>
            <p class="mb-3 text-xs text-gray-500">All conditions must match for the rule to trigger. Leave empty to match all signals.</p>

            @foreach($conditionRows as $i => $row)
                <div class="mb-2 flex gap-2">
                    <x-form-input wire:model="conditionRows.{{ $i }}.field"
                        placeholder="e.g. metadata.severity" class="flex-1" compact />
                    <x-form-select wire:model="conditionRows.{{ $i }}.operator" compact>
                        @foreach($availableOperators as $op)
                            <option value="{{ $op }}">{{ $op }}</option>
                        @endforeach
                    </x-form-select>
                    <x-form-input wire:model="conditionRows.{{ $i }}.value"
                        placeholder="value" class="flex-1" compact />
                    <button type="button" wire:click="removeConditionRow({{ $i }})"
                        class="rounded px-2 text-gray-400 hover:text-red-500">×</button>
                </div>
            @endforeach

            @error('conditionRows.*')
                <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
            @enderror
        </div>

        {{-- Input Mapping --}}
        <div>
            <div class="mb-2 flex items-center justify-between">
                <label class="block text-sm font-medium text-gray-700">Input Mapping (optional)</label>
                <button type="button" wire:click="addMappingRow"
                    class="text-sm text-primary-600 hover:text-primary-700">+ Add mapping</button>
            </div>
            <p class="mb-3 text-xs text-gray-500">Map signal fields to project input_data keys using dot-notation paths.</p>

            @foreach($mappingRows as $i => $row)
                <div class="mb-2 flex items-center gap-2">
                    <x-form-input wire:model="mappingRows.{{ $i }}.target"
                        placeholder="target key (e.g. ticket_title)" class="flex-1" compact />
                    <span class="text-gray-400">←</span>
                    <x-form-input wire:model="mappingRows.{{ $i }}.source"
                        placeholder="source path (e.g. metadata.title)" class="flex-1" compact />
                    <button type="button" wire:click="removeMappingRow({{ $i }})"
                        class="rounded px-2 text-gray-400 hover:text-red-500">×</button>
                </div>
            @endforeach
        </div>

        {{-- Cooldown & Concurrency --}}
        <div class="grid grid-cols-2 gap-4">
            <x-form-input wire:model="cooldown_seconds" type="number" min="0" max="86400"
                label="Cooldown (seconds)" hint="Minimum seconds between triggers. 0 = no cooldown." />
            <x-form-input wire:model="max_concurrent" type="number" min="-1" max="10"
                label="Max Concurrent Runs" hint="Skip trigger if project already has this many active runs. -1 = unlimited." />
        </div>

        {{-- Actions --}}
        <div class="flex justify-end gap-3 pt-2">
            <a href="{{ route('triggers.index') }}"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Create Rule
            </button>
        </div>
    </form>
</div>
