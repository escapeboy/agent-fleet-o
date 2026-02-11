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

            <x-form-select wire:model="workflowId" label="Workflow (optional)" id="workflowId">
                <option value="">No workflow</option>
                @foreach($workflows as $wf)
                    <option value="{{ $wf->id }}">{{ $wf->name }}</option>
                @endforeach
            </x-form-select>
        </div>

        <div class="sm:col-span-2">
            <x-form-textarea wire:model="successCriteria" label="Success Criteria (optional)" id="successCriteria" rows="3"
                placeholder="One criterion per line, e.g.&#10;Landing page has valid HTML with Tailwind CSS&#10;At least 3 affiliate product links included&#10;Page loads under 2 seconds"
                hint="Helps the evaluator decide when the experiment is done. Leave empty for automatic evaluation."
                :error="$errors->first('successCriteria')" />
        </div>

        <div class="mt-2">
            <x-form-checkbox wire:model="autoApprove" id="autoApprove" label="Auto-approve outbound proposals" />
            <p class="ml-6 mt-1 text-xs text-gray-500">Skip the approval gate and let the pipeline proceed automatically.</p>
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
