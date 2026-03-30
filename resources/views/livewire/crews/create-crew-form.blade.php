<div class="mx-auto max-w-3xl">
    {{-- AI Generate Modal --}}
    @if($showGenerateModal)
    <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
        x-data x-on:keydown.escape.window="$wire.set('showGenerateModal', false)">
        <div class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
            <h3 class="mb-1 text-lg font-semibold text-gray-900">Generate Crew from Prompt</h3>
            <p class="mb-4 text-sm text-gray-500">Describe your goal and AI will suggest a crew structure including coordinator, QA, and worker agents.</p>

            <x-form-textarea
                wire:model="generatePrompt"
                label="Goal"
                rows="4"
                placeholder="e.g. Research the latest AI news and produce a weekly digest email with key highlights and analysis."
                :error="$errors->first('generatePrompt')" />

            <div class="mt-4 flex items-center justify-end gap-3">
                <button type="button" wire:click="$set('showGenerateModal', false)"
                    class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    Cancel
                </button>
                <button type="button" wire:click="generateFromPrompt"
                    wire:loading.attr="disabled"
                    class="rounded-lg bg-primary-600 px-5 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    <span wire:loading.remove wire:target="generateFromPrompt">Generate</span>
                    <span wire:loading wire:target="generateFromPrompt">Generating…</span>
                </button>
            </div>
        </div>
    </div>
    @endif

    <form wire:submit="save" class="space-y-6" toolname="create_crew" tooldescription="Create a multi-agent crew with coordinator, QA agent, and workers">
        {{-- Basic Info --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6">
            <div class="mb-4 flex items-center justify-between">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-gray-500">Basic Information</h3>
                <button type="button" wire:click="$set('showGenerateModal', true)"
                    class="flex items-center gap-1.5 rounded-lg border border-primary-300 bg-primary-50 px-3 py-1.5 text-xs font-medium text-primary-700 hover:bg-primary-100">
                    <i class="fas fa-wand-magic-sparkles"></i>
                    AI Generate
                </button>
            </div>

            <div class="space-y-4">
                <x-form-input wire:model="name" label="Crew Name" placeholder="e.g. Research & Content Team" :error="$errors->first('name')"
                    toolparamdescription="Crew name — descriptive identifier" />

                <x-form-textarea wire:model="description" label="Description" placeholder="What does this crew do?" hint="Optional"
                    toolparamdescription="What this crew does and its purpose" />

                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700" toolparamdescription="Process type: sequential (agents work one after another) or hierarchical (coordinator delegates)">Process Type</label>
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
                        <div class="rounded-lg border transition
                            {{ in_array($agent->id, $workerAgentIds) ? 'border-primary-500 bg-primary-50' : 'border-gray-200' }}">
                            <label class="flex cursor-pointer items-center gap-3 p-3">
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

                            {{-- Per-worker constraint fields (shown when worker is selected) --}}
                            @if(in_array($agent->id, $workerAgentIds))
                                <div class="border-t border-primary-200 bg-white px-3 pb-3 pt-2 space-y-2">
                                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider">Permission Policy (optional)</p>
                                    <x-form-input
                                        wire:model="workerConstraints.{{ $agent->id }}.tool_allowlist"
                                        label="Tool Allowlist"
                                        placeholder="e.g. bash, browser_navigate"
                                        hint="Comma-separated tool names. Leave blank for no restriction."
                                        :compact="true" />
                                    <div class="grid grid-cols-2 gap-2">
                                        <x-form-input
                                            wire:model="workerConstraints.{{ $agent->id }}.max_steps"
                                            type="number" min="1" max="100"
                                            label="Max Steps"
                                            hint="Blank = agent default"
                                            :compact="true" />
                                        <x-form-input
                                            wire:model="workerConstraints.{{ $agent->id }}.max_credits"
                                            type="number" min="1"
                                            label="Max Credits"
                                            hint="Blank = no cap"
                                            :compact="true" />
                                    </div>
                                </div>
                            @endif
                        </div>
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

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form-input wire:model="maxTaskIterations" type="number" label="Max Task Retries" min="1" max="10"
                    hint="How many times QA can reject before giving up"
                    :error="$errors->first('maxTaskIterations')" />

                <x-form-input wire:model="qualityThreshold" type="number" label="Quality Threshold" min="0" max="1" step="0.05"
                    hint="Minimum QA score (0.0 - 1.0) to pass"
                    :error="$errors->first('qualityThreshold')" />
            </div>

            {{-- Convergence Mode --}}
            <div class="mt-4">
                <label class="mb-1 block text-sm font-medium text-gray-700">Convergence Mode</label>
                <p class="mb-2 text-xs text-gray-500">Determines when the crew considers its goal complete.</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    @foreach([
                        ['value' => 'any_validated',   'label' => 'Any Validated',    'hint' => 'Done when any task passes QA'],
                        ['value' => 'all_validated',   'label' => 'All Validated',    'hint' => 'Done when all tasks pass QA'],
                        ['value' => 'threshold_ratio', 'label' => 'Threshold Ratio',  'hint' => 'Done when enough tasks pass QA'],
                        ['value' => 'quality_gate',    'label' => 'Quality Gate',     'hint' => 'Done when final score ≥ threshold'],
                    ] as $mode)
                        <label class="relative flex cursor-pointer flex-col rounded-lg border p-3 transition
                            {{ $convergenceMode === $mode['value'] ? 'border-primary-500 bg-primary-50 ring-1 ring-primary-500' : 'border-gray-200 hover:border-gray-300' }}">
                            <input type="radio" wire:model.live="convergenceMode" value="{{ $mode['value'] }}" class="sr-only">
                            <span class="text-sm font-medium text-gray-900">{{ $mode['label'] }}</span>
                            <span class="mt-0.5 text-xs text-gray-500">{{ $mode['hint'] }}</span>
                        </label>
                    @endforeach
                </div>
                @error('convergenceMode') <p class="mt-1 text-sm text-red-600">{{ $message }}</p> @enderror
            </div>

            @if($convergenceMode === 'threshold_ratio')
                <div class="mt-4">
                    <x-form-input wire:model="minValidatedRatio" type="number" label="Min Validated Ratio" min="0" max="1" step="0.05"
                        hint="Fraction of tasks that must pass QA (e.g. 0.8 = 80%)"
                        :error="$errors->first('minValidatedRatio')" />
                </div>
            @endif
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
