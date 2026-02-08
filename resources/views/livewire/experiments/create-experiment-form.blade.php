<div class="mb-6 rounded-xl border border-gray-200 bg-white p-6">
    <h3 class="text-lg font-medium text-gray-900">Create Experiment</h3>

    <form wire:submit="create" class="mt-4 space-y-4">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
                <x-form-input wire:model="title" label="Title" type="text" id="title"
                    placeholder="e.g. Growth email outreach test"
                    :error="$errors->first('title')" />
            </div>

            <div class="sm:col-span-2">
                <x-form-textarea wire:model="thesis" label="Thesis" id="thesis" rows="3"
                    placeholder="What hypothesis are you testing?"
                    :error="$errors->first('thesis')" />
            </div>

            <x-form-select wire:model="track" label="Track" id="track">
                @foreach($tracks as $t)
                    <option value="{{ $t->value }}">{{ ucfirst($t->value) }}</option>
                @endforeach
            </x-form-select>

            <x-form-input wire:model="budgetCapCredits" label="Budget Cap (credits)" type="number" id="budgetCapCredits"
                :error="$errors->first('budgetCapCredits')" />

            <x-form-input wire:model="maxIterations" label="Max Iterations" type="number" id="maxIterations" min="1" max="20"
                :error="$errors->first('maxIterations')" />

            <x-form-input wire:model="maxOutboundCount" label="Max Outbound Count" type="number" id="maxOutboundCount" min="1"
                :error="$errors->first('maxOutboundCount')" />
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Create Experiment
            </button>
            <button type="button" wire:click="$parent.toggle('showCreateForm')"
                class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </button>
        </div>
    </form>
</div>
