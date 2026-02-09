<div class="mx-auto max-w-3xl">
    <form wire:submit="save" class="space-y-6">
        {{-- Basic Info --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500">Basic Information</h3>

            <div class="space-y-4">
                <x-form-input wire:model="name" label="Crew Name" placeholder="e.g. Research & Content Team" :error="$errors->first('name')" />

                <x-form-textarea wire:model="description" label="Description" placeholder="What does this crew do?" hint="Optional" />

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Process Type</label>
                    <div class="grid grid-cols-3 gap-3">
                        @foreach($processTypes as $pt)
                            <label class="relative flex cursor-pointer items-start rounded-lg border p-3 transition
                                {{ $processType === $pt->value ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500' : 'border-gray-200 hover:border-gray-300' }}">
                                <input type="radio" wire:model="processType" value="{{ $pt->value }}" class="sr-only">
                                <div>
                                    <span class="block text-sm font-medium text-gray-900">{{ $pt->label() }}</span>
                                    <span class="mt-0.5 block text-xs text-gray-500">{{ $pt->description() }}</span>
                                </div>
                            </label>
                        @endforeach
                    </div>
                    @error('processType') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        {{-- Coordinator Agent --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500">Coordinator Agent</h3>
            <p class="mb-3 text-xs text-gray-500">The coordinator decomposes goals, delegates tasks, and synthesizes results.</p>

            <x-form-select wire:model="coordinatorAgentId" label="Select Coordinator" :error="$errors->first('coordinatorAgentId')">
                <option value="">Choose an agent...</option>
                @foreach($agents as $agent)
                    <option value="{{ $agent->id }}">{{ $agent->name }} ({{ $agent->role ?? 'No role' }})</option>
                @endforeach
            </x-form-select>
        </div>

        {{-- QA Agent --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500">QA Agent</h3>
            <p class="mb-3 text-xs text-gray-500">Validates every task output and the final result. Must be different from the coordinator.</p>

            <x-form-select wire:model="qaAgentId" label="Select QA Agent" :error="$errors->first('qaAgentId')">
                <option value="">Choose an agent...</option>
                @foreach($agents as $agent)
                    @if($agent->id !== $coordinatorAgentId)
                        <option value="{{ $agent->id }}">{{ $agent->name }} ({{ $agent->role ?? 'No role' }})</option>
                    @endif
                @endforeach
            </x-form-select>
        </div>

        {{-- Worker Agents --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500">Worker Agents</h3>
            <p class="mb-3 text-xs text-gray-500">Workers execute delegated tasks. Leave empty for coordinator-only mode.</p>

            <div class="space-y-2">
                @foreach($agents as $agent)
                    @if($agent->id !== $coordinatorAgentId && $agent->id !== $qaAgentId)
                        <label class="flex cursor-pointer items-center gap-3 rounded-lg border p-3 transition
                            {{ in_array($agent->id, $workerAgentIds) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                            <input type="checkbox" wire:click="toggleWorker('{{ $agent->id }}')"
                                {{ in_array($agent->id, $workerAgentIds) ? 'checked' : '' }}
                                class="h-4 w-4 rounded border-gray-300 text-primary-600">
                            <div class="flex-1">
                                <span class="text-sm font-medium text-gray-900">{{ $agent->name }}</span>
                                @if($agent->role)
                                    <span class="ml-2 text-xs text-gray-500">{{ $agent->role }}</span>
                                @endif
                                @if($agent->goal)
                                    <p class="mt-0.5 text-xs text-gray-400">{{ Str::limit($agent->goal, 80) }}</p>
                                @endif
                            </div>
                            <span class="text-xs text-gray-400">{{ $agent->skills_count ?? $agent->skills()->count() }} skills</span>
                        </label>
                    @endif
                @endforeach
            </div>

            @if($agents->count() < 3)
                <p class="mt-3 text-xs text-gray-400">
                    You need at least 2 agents (coordinator + QA). <a href="{{ route('agents.create') }}" class="text-primary-600 hover:underline">Create more agents</a>.
                </p>
            @endif
        </div>

        {{-- Settings --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <h3 class="mb-4 text-sm font-semibold uppercase tracking-wider text-gray-500">Settings</h3>

            <div class="grid grid-cols-2 gap-4">
                <x-form-input wire:model="maxTaskIterations" type="number" label="Max Task Retries" min="1" max="10"
                    hint="How many times QA can reject before giving up"
                    :error="$errors->first('maxTaskIterations')" />

                <x-form-input wire:model="qualityThreshold" type="number" label="Quality Threshold" min="0" max="1" step="0.05"
                    hint="Minimum QA score (0.0 - 1.0) to pass"
                    :error="$errors->first('qualityThreshold')" />
            </div>
        </div>

        {{-- Submit --}}
        <div class="flex items-center justify-end gap-3">
            <a href="{{ route('crews.index') }}" class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Cancel
            </a>
            <button type="submit" class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                Create Crew
            </button>
        </div>
    </form>
</div>
