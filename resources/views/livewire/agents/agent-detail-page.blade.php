<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    {{-- Tool loop warning badge: shown when recent executions average >= warning threshold --}}
    @if($avgSteps >= config('agent.tool_loop.warning_threshold', 8))
        <div class="mb-4 flex items-center gap-3 rounded-lg border border-yellow-200 bg-yellow-50 p-3">
            <i class="fa-solid fa-triangle-exclamation text-lg shrink-0 text-yellow-600"></i>
            <div class="flex-1">
                <p class="text-sm font-medium text-yellow-800">Tool Loop Warning</p>
                <p class="text-xs text-yellow-700">
                    This agent averaged <strong>{{ number_format($avgSteps, 1) }} LLM steps</strong> over its last 5 executions
                    (warning threshold: {{ config('agent.tool_loop.warning_threshold', 8) }},
                    critical: {{ config('agent.tool_loop.critical_threshold', 12) }}).
                    Consider reviewing the agent's goal and tool configuration.
                </p>
            </div>
        </div>
    @endif

    @if($editing)
        {{-- ====== EDIT MODE ====== --}}
        <div class="rounded-xl border border-primary-200 bg-white p-6">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Edit Agent</h3>

            <div class="space-y-4">
                {{-- Name & Role --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-form-input wire:model="editName" label="Name" type="text"
                        :error="$errors->first('editName')" />
                    <x-form-input wire:model="editRole" label="Role" type="text"
                        :error="$errors->first('editRole')" />
                </div>

                {{-- Goal --}}
                <x-form-textarea wire:model="editGoal" label="Goal" rows="2"
                    :error="$errors->first('editGoal')" />

                {{-- Backstory --}}
                <x-form-textarea wire:model="editBackstory" label="Backstory (optional)" rows="3" />

                {{-- Provider / Model / Budget --}}
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                    <x-form-select wire:model.live="editProvider" label="Provider"
                        :error="$errors->first('editProvider')">
                        @foreach($providers as $key => $p)
                            <option value="{{ $key }}">{{ $p['name'] }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="editModel" label="Model"
                        :error="$errors->first('editModel')">
                        @foreach($providers[$editProvider]['models'] ?? [] as $modelKey => $modelInfo)
                            <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-input wire:model.number="editBudgetCap" label="Budget Cap (credits)" type="number" min="0"
                        hint="Leave empty for unlimited" />
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-form-select wire:model="editExecutionTier" label="Execution Tier">
                        @foreach(\App\Domain\Agent\Enums\ExecutionTier::cases() as $tier)
                            <option value="{{ $tier->value }}">{{ $tier->label() }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="editToolProfile" label="Tool Profile" hint="Restricts which tools this agent can access">
                        <option value="">No restriction (all tools)</option>
                        @foreach(config('tool_profiles.profiles', []) as $key => $profile)
                            <option value="{{ $key }}">{{ $profile['label'] }} — {{ $profile['description'] }}</option>
                        @endforeach
                    </x-form-select>
                </div>

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-form-select wire:model="editReasoningEffort" label="Reasoning Effort" hint="Extended thinking budget for the model. Anthropic Claude only; ignored on OpenAI and Google.">
                        @foreach(\App\Infrastructure\AI\Enums\ReasoningEffort::cases() as $effort)
                            <option value="{{ $effort->value }}">{{ $effort->label() }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="editEnvironment" label="Environment" hint="Preset that auto-attaches a tool bundle">
                        <option value="">No preset</option>
                        @foreach(\App\Domain\Agent\Enums\AgentEnvironment::cases() as $env)
                            <option value="{{ $env->value }}">{{ $env->label() }}</option>
                        @endforeach
                    </x-form-select>
                </div>

                <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                    <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                        <input type="checkbox" wire:model.live="editUseToolSearch" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                        Enable Tool Search
                    </label>
                    <p class="mt-1 text-xs text-gray-500">Auto-discover relevant tools from the team pool by matching the user prompt against tool descriptions.</p>
                    @if($editUseToolSearch)
                        <div class="mt-3">
                            <x-form-input wire:model="editToolSearchTopK" label="Top K" type="number" min="1" max="20" hint="Maximum tools surfaced per agent invocation (1–20)." />
                        </div>
                    @endif
                </div>

                @if(!empty($providers[$editProvider]['local']))
                    <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                        Local agent — executes on the host machine using its own CLI process. No per-request API costs.
                    </div>
                @endif

                {{-- Fallback Chain --}}
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">Fallback Chain</label>
                    <p class="mb-2 text-xs text-gray-500">If the primary provider fails or is rate-limited, requests fall through to fallbacks in order.</p>

                    @foreach($editFallbackChain as $index => $fallback)
                        <div class="mb-2 flex items-center gap-2" wire:key="edit-fb-{{ $index }}">
                            <span class="text-xs font-medium text-gray-400 w-6">{{ $index + 1 }}.</span>
                            <select wire:model.live="editFallbackChain.{{ $index }}.provider"
                                class="rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                                @foreach($providers as $key => $p)
                                    <option value="{{ $key }}">{{ $p['name'] }}</option>
                                @endforeach
                            </select>
                            <select wire:model="editFallbackChain.{{ $index }}.model"
                                class="flex-1 rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                                @foreach($providers[$editFallbackChain[$index]['provider'] ?? 'anthropic']['models'] ?? [] as $modelKey => $modelInfo)
                                    <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                                @endforeach
                            </select>
                            <button wire:click="removeFallback({{ $index }})" type="button"
                                class="rounded p-1 text-red-500 hover:bg-red-50">
                                <i class="fa-solid fa-xmark text-base"></i>
                            </button>
                        </div>
                    @endforeach

                    <button wire:click="addFallback" type="button"
                        class="mt-1 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-500 hover:border-gray-400 hover:text-gray-700">
                        + Add Fallback
                    </button>
                </div>

                {{-- Skill Assignment --}}
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">Assign Skills</label>
                    @if($availableSkills->isNotEmpty())
                        <div
                            x-data="{
                                search: '',
                                selected: $wire.entangle('editSkillIds'),
                                items: @js($availableSkills->map(fn($s) => ['id' => $s->id, 'name' => $s->name, 'type' => $s->type->label()])->values()),
                                get filtered() {
                                    const q = this.search.toLowerCase();
                                    return q ? this.items.filter(i => i.name.toLowerCase().includes(q)) : this.items;
                                },
                                toggle(id) {
                                    const idx = this.selected.indexOf(id);
                                    this.selected = idx === -1 ? [...this.selected, id] : this.selected.filter(i => i !== id);
                                },
                                isSelected(id) { return this.selected.includes(id); }
                            }"
                        >
                            <input
                                x-show="items.length >= 6"
                                x-model.debounce.200ms="search"
                                type="text"
                                placeholder="Filter skills..."
                                class="mb-2 w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"
                            />
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <template x-for="skill in filtered" :key="skill.id">
                                    <button type="button"
                                        x-on:click="toggle(skill.id)"
                                        class="flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition"
                                        :class="isSelected(skill.id) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300'">
                                        <div class="flex h-5 w-5 shrink-0 items-center justify-center rounded border"
                                            :class="isSelected(skill.id) ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-300'">
                                            <template x-if="isSelected(skill.id)">
                                                <i class="fa-solid fa-check text-xs"></i>
                                            </template>
                                        </div>
                                        <div>
                                            <div class="font-medium" x-text="skill.name"></div>
                                            <div class="text-xs text-gray-500" x-text="skill.type"></div>
                                        </div>
                                    </button>
                                </template>
                                <p x-show="filtered.length === 0" class="col-span-2 py-3 text-center text-sm text-gray-400">No skills match your search.</p>
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No active skills available.</p>
                    @endif
                </div>

                {{-- Tool Federation --}}
                <div class="rounded-lg border border-gray-200 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Tool Federation</p>
                            <p class="text-xs text-gray-500 mt-0.5">Allow this agent to dynamically access all active team tools without explicit assignment</p>
                        </div>
                        <x-form-checkbox name="editUseFederation" wire:model.live="editUseFederation" />
                    </div>
                    @if($editUseFederation)
                        <div class="mt-3">
                            <x-form-select name="editFederationGroupId" wire:model="editFederationGroupId" label="Limit to federation group (optional)">
                                <option value="">All team tools</option>
                                @foreach(\App\Domain\Tool\Models\ToolFederationGroup::where('is_active', true)->orderBy('name')->get() as $group)
                                    <option value="{{ $group->id }}">{{ $group->name }} ({{ count($group->tool_ids ?? []) }} tools)</option>
                                @endforeach
                            </x-form-select>
                        </div>
                    @endif
                </div>

                {{-- Memory & Scout Phase --}}
                <div class="rounded-lg border border-gray-200 p-4 space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Use Memory</p>
                            <p class="text-xs text-gray-500 mt-0.5">Inject relevant memories from the team memory store into each execution</p>
                        </div>
                        <x-form-checkbox name="editUseMemory" wire:model.live="editUseMemory" />
                    </div>
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-900">Enable Scout Phase</p>
                            <p class="text-xs text-gray-500 mt-0.5">Run a cheap pre-execution LLM call to generate targeted memory retrieval queries before the main execution</p>
                        </div>
                        <x-form-checkbox name="editEnableScoutPhase" wire:model.live="editEnableScoutPhase" />
                    </div>
                </div>

                {{-- Tool Assignment --}}
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">Assign Tools</label>
                    <p class="mb-3 text-xs text-gray-500">When tools are assigned, the agent uses an agentic loop where the LLM decides which tools to call.</p>
                    @if($availableTools->isNotEmpty())
                        <div
                            x-data="{
                                search: '',
                                selected: $wire.entangle('editToolIds'),
                                items: @js($availableTools->map(fn($t) => ['id' => $t->id, 'name' => $t->name, 'type' => $t->type->label()])->values()),
                                get filtered() {
                                    const q = this.search.toLowerCase();
                                    return q ? this.items.filter(i => i.name.toLowerCase().includes(q)) : this.items;
                                },
                                toggle(id) {
                                    const idx = this.selected.indexOf(id);
                                    this.selected = idx === -1 ? [...this.selected, id] : this.selected.filter(i => i !== id);
                                },
                                isSelected(id) { return this.selected.includes(id); }
                            }"
                        >
                            <input
                                x-show="items.length >= 6"
                                x-model.debounce.200ms="search"
                                type="text"
                                placeholder="Filter tools..."
                                class="mb-2 w-full rounded-lg border border-gray-300 px-3 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500"
                            />
                            <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
                                <template x-for="tool in filtered" :key="tool.id">
                                    <button type="button"
                                        x-on:click="toggle(tool.id)"
                                        class="flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition"
                                        :class="isSelected(tool.id) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300'">
                                        <div class="flex h-5 w-5 shrink-0 items-center justify-center rounded border"
                                            :class="isSelected(tool.id) ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-300'">
                                            <template x-if="isSelected(tool.id)">
                                                <i class="fa-solid fa-check text-xs"></i>
                                            </template>
                                        </div>
                                        <div>
                                            <div class="font-medium" x-text="tool.name"></div>
                                            <div class="text-xs text-gray-500" x-text="tool.type"></div>
                                        </div>
                                    </button>
                                </template>
                                <p x-show="filtered.length === 0" class="col-span-2 py-3 text-center text-sm text-gray-400">No tools match your search.</p>
                            </div>
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No active tools available. <a href="{{ route('tools.create') }}" class="text-primary-600 hover:underline">Create a tool first.</a></p>
                    @endif
                </div>

                {{-- Knowledge Bases --}}
                @if($availableKnowledgeBases->isNotEmpty())
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-2">Knowledge Bases</label>
                    <p class="text-xs text-gray-500 mb-3">Select knowledge bases to provide RAG context during agent execution.</p>
                    <div class="space-y-2 max-h-48 overflow-y-auto rounded-lg border border-gray-200 p-3">
                        @foreach($availableKnowledgeBases as $kb)
                            <label class="flex items-center gap-3 cursor-pointer group">
                                <input type="checkbox" wire:model="editKnowledgeBaseIds" value="{{ $kb->id }}"
                                    class="h-4 w-4 rounded border-gray-300 text-primary-600 focus:ring-primary-500">
                                <div class="min-w-0 flex-1">
                                    <span class="text-sm text-gray-900 group-hover:text-primary-700">{{ $kb->name }}</span>
                                    <span class="ml-1 text-xs text-gray-400">({{ number_format($kb->chunks_count) }} chunks)</span>
                                </div>
                                <span class="shrink-0 rounded-full px-1.5 py-0.5 text-[10px] font-medium {{ $kb->status->color() === 'green' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500' }}">
                                    {{ $kb->status->label() }}
                                </span>
                            </label>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- A/B Evaluation --}}
                <div class="rounded-lg border border-gray-200 p-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="text-sm font-medium text-gray-900">A/B Evaluation</p>
                            <p class="text-xs text-gray-500 mt-0.5">Enable A/B testing to compare this agent's performance against variants</p>
                        </div>
                        <x-form-checkbox name="editEvaluationEnabled" wire:model.live="editEvaluationEnabled" />
                    </div>
                    @if($editEvaluationEnabled)
                        <div class="mt-3">
                            <x-form-input wire:model.number="editEvaluationSampleRate" label="Sample Rate" type="number" min="0" max="1" step="0.01"
                                placeholder="0.0 - 1.0" hint="Fraction of requests to include in the evaluation (e.g. 0.1 = 10%)" />
                        </div>
                    @endif
                </div>

                {{-- Linked Git Repositories --}}
                @if($availableGitRepositories->isNotEmpty())
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">Linked Git Repositories</label>
                    <p class="mb-3 text-xs text-gray-500">Linked repos inject a repo map into the agent's context at execution time.</p>
                    <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                        @foreach($availableGitRepositories as $repo)
                            <button type="button" wire:click="toggleGitRepository('{{ $repo->id }}')"
                                class="flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors
                                    {{ in_array($repo->id, $editGitRepositoryIds) ? 'border-primary-300 bg-primary-50 text-primary-800' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-200 hover:bg-primary-50/40' }}">
                                <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border {{ in_array($repo->id, $editGitRepositoryIds) ? 'border-primary-500 bg-primary-500' : 'border-gray-300' }}">
                                    @if(in_array($repo->id, $editGitRepositoryIds))
                                        <i class="fa-solid fa-check text-xs text-white"></i>
                                    @endif
                                </span>
                                <span class="min-w-0 truncate">{{ $repo->name }}</span>
                            </button>
                        @endforeach
                    </div>
                </div>
                @endif

                {{-- Actions --}}
                <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                    <button wire:click="deleteAgent" wire:confirm="Are you sure you want to delete this agent? This cannot be undone."
                        class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                        Delete Agent
                    </button>
                    <div class="flex gap-2">
                        <button wire:click="cancelEdit"
                            class="rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 hover:bg-gray-50">
                            Cancel
                        </button>
                        <button wire:click="save"
                            class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Save Changes
                        </button>
                    </div>
                </div>
            </div>
        </div>

    @else
        {{-- ====== VIEW MODE ====== --}}

        {{-- Header --}}
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $agent->name }}</h2>
                    <x-status-badge :status="$agent->status->value" />
                    @php $tier = \App\Domain\Agent\Enums\ExecutionTier::fromConfig($agent->config ?? []); @endphp
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-semibold {{ $tier->badgeClass() }}">{{ $tier->label() }}</span>
                </div>
                @if($agent->role)
                    <p class="mt-1 text-sm font-medium text-gray-600">{{ $agent->role }}</p>
                @endif
                @if($agent->goal)
                    <p class="mt-0.5 text-sm text-gray-500">{{ $agent->goal }}</p>
                @endif
                <div class="mt-2 flex flex-wrap items-center gap-1.5">
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-600"
                        title="Resolved from: {{ match($resolvedProvider['source']) { 'agent' => 'Agent configuration', 'team' => 'Team default', 'platform' => 'Platform settings', 'config' => 'System default', default => $resolvedProvider['source'] } }}">
                        {{ $resolvedProvider['provider'] }}/{{ $resolvedProvider['model'] }}
                    </span>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($resolvedProvider['source']) {
                        'agent' => 'bg-blue-50 text-blue-700',
                        'team' => 'bg-purple-50 text-purple-700',
                        'platform' => 'bg-amber-50 text-amber-700',
                        'config' => 'bg-gray-50 text-gray-500',
                        default => 'bg-gray-50 text-gray-500',
                    } }}">
                        {{ match($resolvedProvider['source']) {
                            'agent' => 'agent',
                            'team' => 'team default',
                            'platform' => 'platform',
                            'config' => 'system default',
                            default => $resolvedProvider['source'],
                        } }}
                    </span>
                    @foreach($agent->config['fallback_chain'] ?? [] as $fb)
                        <span class="text-xs text-gray-400">&rarr;</span>
                        <span class="inline-flex items-center rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500">
                            {{ $fb['provider'] }}/{{ $fb['model'] }}
                        </span>
                    @endforeach
                </div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <a href="{{ route('agents.voice', $agent) }}"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50"
                    title="Voice Session">
                    <i class="fa-solid fa-microphone text-xs"></i>
                    Voice
                </a>
                <div x-data="{ showExport: false }" class="relative">
                    <button @click="showExport = !showExport" class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Export Agent">
                        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                    </button>
                    <div x-show="showExport" @click.away="showExport = false" x-transition
                        class="absolute right-0 z-10 mt-2 w-64 rounded-lg border border-gray-200 bg-white p-4 shadow-lg">
                        <h4 class="mb-3 text-sm font-semibold text-gray-900">Export Workspace</h4>
                        <x-form-select wire:model="exportFormat" label="Format" compact>
                            <option value="zip">ZIP archive</option>
                            <option value="yaml">YAML file</option>
                        </x-form-select>
                        <div class="mt-2">
                            <x-form-checkbox wire:model="exportIncludeMemories" label="Include memories" />
                        </div>
                        <button wire:click="exportWorkspace" @click="showExport = false"
                            class="mt-3 w-full rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                            Download
                        </button>
                    </div>
                </div>
                <button wire:click="startEdit" class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Edit Agent">
                    <i class="fa-solid fa-pen text-base"></i>
                </button>
                <button wire:click="toggleStatus"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium {{ $agent->status === \App\Domain\Agent\Enums\AgentStatus::Active ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-green-300 text-green-700 hover:bg-green-50' }}">
                    {{ $agent->status === \App\Domain\Agent\Enums\AgentStatus::Active ? 'Disable' : 'Enable' }}
                </button>
                <x-send-to-assistant-button
                    :message="'How should I configure this agent? Name: ' . $agent->name . ($agent->role ? '. Role: ' . $agent->role : '') . ($agent->goal ? '. Goal: ' . $agent->goal : '')"
                />
            </div>
        </div>

        {{-- Stats --}}
        <div class="mb-6 grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-5">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $skills->count() }}</div>
                <div class="text-sm text-gray-500">Skills</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $tools->count() }}</div>
                <div class="text-sm text-gray-500">Tools</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $runtimeState?->total_executions ?? 0 }}</div>
                <div class="text-sm text-gray-500">Total Executions</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ number_format($runtimeState?->total_cost_credits ?? 0) }}</div>
                <div class="text-sm text-gray-500">Lifetime Credits</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $agent->budget_cap_credits ? number_format($agent->budgetRemainingCredits()) : 'Unlimited' }}</div>
                <div class="text-sm text-gray-500">Budget Remaining</div>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="mb-4 border-b border-gray-200">
            <nav class="-mb-px flex space-x-8 overflow-x-auto scrollbar-none">
                @foreach(['overview' => 'Overview', 'identity' => 'System Prompt', 'memory' => 'Memory', 'knowledge' => 'Knowledge', 'skills' => 'Skills', 'tools' => 'Tools', 'hooks' => 'Hooks', 'executions' => 'Executions', 'history' => 'Config History', 'risk' => 'Risk Profile', 'evolution' => 'Evolution', 'heartbeat' => 'Heartbeat'] as $tab => $label)
                    <button wire:click="$set('activeTab', '{{ $tab }}')"
                        class="whitespace-nowrap border-b-2 py-3 text-sm font-medium {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Tab Content --}}
        @if($activeTab === 'overview')
            <div class="space-y-6">
                @if($agent->backstory)
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-2 text-sm font-semibold text-gray-700">Backstory</h3>
                        <p class="text-sm text-gray-600 whitespace-pre-wrap">{{ $agent->backstory }}</p>
                    </div>
                @endif

                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-2 text-sm font-semibold text-gray-700">Capabilities</h3>
                        @if(!empty($agent->capabilities))
                            <pre class="max-h-32 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($agent->capabilities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        @else
                            <p class="text-xs italic text-gray-400">None defined</p>
                        @endif
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-2 text-sm font-semibold text-gray-700">Constraints</h3>
                        @if(!empty($agent->constraints))
                            <pre class="max-h-32 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($agent->constraints, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                        @else
                            <p class="text-xs italic text-gray-400">None defined</p>
                        @endif
                    </div>
                </div>

                {{-- Execution settings summary (read-only, edit via startEdit) --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Execution Settings</h3>
                    <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Reasoning Effort</dt>
                            <dd class="mt-0.5 text-gray-900">
                                @php($effortEnum = \App\Infrastructure\AI\Enums\ReasoningEffort::tryFrom($agent->config['reasoning_effort'] ?? ''))
                                @if($effortEnum)
                                    <span class="inline-flex items-center rounded-full bg-purple-50 px-2 py-0.5 text-xs font-medium text-purple-700">{{ $effortEnum->label() }}</span>
                                @else
                                    <span class="text-xs text-gray-400">Not set</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Environment</dt>
                            <dd class="mt-0.5 text-gray-900">
                                @if($agent->environment)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">{{ $agent->environment->label() }}</span>
                                @else
                                    <span class="text-xs text-gray-400">Not set</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Tool Search</dt>
                            <dd class="mt-0.5 text-gray-900">
                                @if($agent->config['use_tool_search'] ?? false)
                                    <span class="inline-flex items-center rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">
                                        Enabled · top {{ $agent->config['tool_search_top_k'] ?? 5 }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-400">Not set</span>
                                @endif
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs uppercase tracking-wide text-gray-500">Thinking Budget</dt>
                            <dd class="mt-0.5 text-gray-900">
                                @if(!empty($agent->config['thinking_budget']))
                                    <span class="font-mono text-xs">{{ number_format($agent->config['thinking_budget']) }} tokens</span>
                                @else
                                    <span class="text-xs text-gray-400">Not set</span>
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </div>

        @elseif($activeTab === 'identity')
            <div class="space-y-6">
                <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Prompt Mode</h3>
                            <p class="mt-0.5 text-xs text-gray-500">Choose between a structured identity template or the classic backstory field.</p>
                        </div>
                        <x-form-checkbox wire:model.live="useStructuredTemplate" label="Use structured template" />
                    </div>

                    @if($useStructuredTemplate)
                        <div class="space-y-4">
                            <x-form-textarea wire:model="templatePersonality" label="Personality" rows="3"
                                hint="Describe the agent's persona, tone, and communication style." />

                            {{-- Rules --}}
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">Rules</label>
                                <p class="mb-2 text-xs text-gray-500">Explicit behavioral constraints the agent must follow.</p>
                                @if(!empty($templateRules))
                                    <ul class="mb-2 space-y-1">
                                        @foreach($templateRules as $index => $rule)
                                            <li class="flex items-center gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm" wire:key="rule-{{ $index }}">
                                                <span class="flex-1 text-gray-700">{{ $rule }}</span>
                                                <button wire:click="removeRule({{ $index }})" type="button"
                                                    class="shrink-0 rounded p-1 text-red-400 hover:bg-red-50 hover:text-red-600">
                                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </li>
                                        @endforeach
                                    </ul>
                                @endif
                                <div class="flex gap-2">
                                    <x-form-input wire:model="newRule" type="text" placeholder="Add a rule..." class="flex-1" compact
                                        wire:keydown.enter.prevent="addRule" />
                                    <button wire:click="addRule" type="button"
                                        class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                                        Add
                                    </button>
                                </div>
                            </div>

                            <x-form-textarea wire:model="templateContextInjection" label="Context Injection" rows="3" mono
                                hint="Template for injecting dynamic context. Variables: @{{agent.name}}, @{{agent.role}}, @{{recent_memories}}, @{{knowledge_context}}" />

                            <x-form-textarea wire:model="templateOutputFormat" label="Output Format" rows="2"
                                hint="Desired output structure or formatting instructions." />

                            <div class="flex justify-end">
                                <button wire:click="saveIdentityTemplate"
                                    class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                                    Save Template
                                </button>
                            </div>

                            {{-- Preview --}}
                            @if($templatePersonality || !empty($templateRules))
                                <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                                    <h4 class="mb-2 text-xs font-semibold uppercase tracking-wider text-gray-500">Compiled Preview</h4>
                                    <div class="whitespace-pre-wrap font-mono text-xs text-gray-700">@if($templatePersonality)<span class="text-gray-400">[Personality]</span>
{{ $templatePersonality }}
@endif
@if(!empty($templateRules))<span class="text-gray-400">[Rules]</span>
@foreach($templateRules as $r)- {{ $r }}
@endforeach
@endif
@if($templateContextInjection)<span class="text-gray-400">[Context]</span>
{{ $templateContextInjection }}
@endif
@if($templateOutputFormat)<span class="text-gray-400">[Output Format]</span>
{{ $templateOutputFormat }}
@endif</div>
                                </div>
                            @endif
                        </div>
                    @else
                        <div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
                            <h4 class="mb-2 text-sm font-semibold text-gray-700">Classic Backstory</h4>
                            @if($agent->backstory)
                                <p class="whitespace-pre-wrap text-sm text-gray-600">{{ $agent->backstory }}</p>
                            @else
                                <p class="text-sm italic text-gray-400">No backstory defined. Click "Edit" above to add one.</p>
                            @endif
                        </div>
                        @if($agent->system_prompt_template)
                            <button wire:click="saveIdentityTemplate"
                                class="mt-2 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50">
                                Clear structured template
                            </button>
                        @endif
                    @endif
                </div>
            </div>

        @elseif($activeTab === 'memory')
            <div class="space-y-4">
                @php $memories = $this->agentMemories; @endphp

                @if($memories->isEmpty())
                    <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center">
                        <svg class="mx-auto mb-2 h-8 w-8 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.663 17h4.673M12 3v1m6.364 1.636l-.707.707M21 12h-1M4 12H3m3.343-5.657l-.707-.707m2.828 9.9a5 5 0 117.072 0l-.548.547A3.374 3.374 0 0014 18.469V19a2 2 0 11-4 0v-.531c0-.895-.356-1.754-.988-2.386l-.548-.547z"/></svg>
                        <p class="text-sm text-gray-500">No memories stored for this agent.</p>
                        <p class="mt-1 text-xs text-gray-400">Memories are created automatically during execution or via the Memory API.</p>
                    </div>
                @else
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                        <div class="border-b border-gray-200 px-6 py-3">
                            <p class="text-sm text-gray-500">{{ $memories->count() }} most recent memories</p>
                        </div>
                        <ul class="divide-y divide-gray-100">
                            @foreach($memories as $memory)
                                <li class="px-6 py-4">
                                    <div class="flex items-start justify-between gap-3">
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm text-gray-800">{{ \Illuminate\Support\Str::limit($memory->content, 200) }}</p>
                                            <div class="mt-2 flex flex-wrap items-center gap-1.5">
                                                @if($memory->source_type === 'auto-flush')
                                                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-2 py-0.5 text-xs font-medium text-indigo-700">Auto</span>
                                                @endif
                                                @if($memory->importance)
                                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                                        {{ $memory->importance >= 0.7 ? 'bg-amber-100 text-amber-700' : ($memory->importance >= 0.4 ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600') }}">
                                                        {{ number_format($memory->importance, 1) }}
                                                    </span>
                                                @endif
                                                @foreach($memory->tags ?? [] as $tag)
                                                    @if($tag !== 'auto-flush')
                                                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2 py-0.5 text-xs text-gray-600">{{ $tag }}</span>
                                                    @endif
                                                @endforeach
                                            </div>
                                        </div>
                                        <span class="shrink-0 text-xs text-gray-400" title="{{ $memory->created_at }}">{{ $memory->created_at->diffForHumans() }}</span>
                                    </div>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'knowledge')
            <div class="space-y-4">
                @php
                    $linkedKbs = $agent->knowledgeBases()->withCount('chunks')->get();
                @endphp

                @if($linkedKbs->isEmpty())
                    <div class="rounded-xl border border-dashed border-gray-300 bg-white p-8 text-center">
                        <i class="fas fa-book-open mb-2 text-2xl text-gray-300"></i>
                        <p class="text-sm text-gray-500">No knowledge bases linked to this agent.</p>
                        <p class="mt-1 text-xs text-gray-400">Click "Edit" above to assign knowledge bases, or <a href="{{ route('knowledge.index') }}" class="text-primary-600 hover:underline">create one</a>.</p>
                    </div>
                @else
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        @foreach($linkedKbs as $kb)
                            @php
                                $statusColor = match($kb->status->color()) {
                                    'green' => 'bg-green-100 text-green-700',
                                    'blue' => 'bg-blue-100 text-blue-700',
                                    'red' => 'bg-red-100 text-red-700',
                                    default => 'bg-gray-100 text-gray-600',
                                };
                            @endphp
                            <div class="rounded-xl border border-gray-200 bg-white p-4">
                                <div class="mb-2 flex items-start justify-between">
                                    <h4 class="text-sm font-semibold text-gray-900">{{ $kb->name }}</h4>
                                    <span class="ml-2 shrink-0 rounded-full px-2 py-0.5 text-xs font-medium {{ $statusColor }}">
                                        {{ $kb->status->label() }}
                                    </span>
                                </div>
                                @if($kb->description)
                                    <p class="mb-3 text-xs text-gray-500">{{ Str::limit($kb->description, 100) }}</p>
                                @endif
                                <dl class="grid grid-cols-2 gap-2 text-xs">
                                    <div>
                                        <dt class="text-gray-400">Chunks</dt>
                                        <dd class="font-medium text-gray-700">{{ number_format($kb->chunks_count) }}</dd>
                                    </div>
                                    @if($kb->last_ingested_at)
                                    <div>
                                        <dt class="text-gray-400">Last ingested</dt>
                                        <dd class="font-medium text-gray-700">{{ $kb->last_ingested_at->diffForHumans() }}</dd>
                                    </div>
                                    @endif
                                </dl>
                                <div class="mt-3 border-t border-gray-100 pt-2">
                                    <a href="{{ route('knowledge.index') }}" class="text-xs font-medium text-primary-600 hover:text-primary-800">
                                        Manage documents &rarr;
                                    </a>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'skills')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Skill</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Priority</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($skills as $skill)
                            <tr>
                                <td class="px-6 py-4">
                                    <a href="{{ route('skills.show', $skill) }}" class="font-medium text-primary-600 hover:text-primary-800">{{ $skill->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $skill->type->label() }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $skill->pivot->priority }}</td>
                                <td class="px-6 py-4"><x-status-badge :status="$skill->status->value" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-400">No skills assigned</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

        @elseif($activeTab === 'tools')
            @if($recentToolSearches->isNotEmpty())
                <div class="mb-4 rounded-xl border border-indigo-200 bg-indigo-50 p-4">
                    <div class="mb-2 flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-indigo-900">Recent tool search events</h3>
                        <a href="{{ route('tools.search-history') }}?agentFilter={{ $agent->id }}"
                            class="text-xs text-indigo-700 hover:underline">View all →</a>
                    </div>
                    <ul class="space-y-1.5 text-xs text-indigo-800">
                        @foreach($recentToolSearches as $evt)
                            <li class="flex items-start gap-2">
                                <span class="text-indigo-500">{{ $evt->created_at->diffForHumans() }}</span>
                                <span class="font-mono flex-1 truncate" title="{{ $evt->query }}">{{ \Illuminate\Support\Str::limit($evt->query, 80) }}</span>
                                <span class="text-indigo-700 whitespace-nowrap">
                                    {{ $evt->matched_count }}/{{ $evt->pool_size }} matched
                                    @if(!empty($evt->matched_slugs))
                                        → {{ implode(', ', array_slice($evt->matched_slugs, 0, 3)) }}{{ count($evt->matched_slugs) > 3 ? '…' : '' }}
                                    @endif
                                </span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tool</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Functions</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Priority</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Approval</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($tools as $tool)
                            @php
                                $approvalMode = $tool->pivot->approval_mode?->value ?? 'auto';
                                $approvalTimeout = $tool->pivot->approval_timeout_minutes ?? 30;
                                $timeoutAction = $tool->pivot->approval_timeout_action?->value ?? 'deny';
                            @endphp
                            <tr x-data="{ expanded: false }">
                                <td class="px-6 py-4">
                                    <a href="{{ route('tools.show', $tool) }}" class="font-medium text-primary-600 hover:text-primary-800">{{ $tool->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->type->label() }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->functionCount() }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->pivot->priority }}</td>
                                <td class="px-6 py-4">
                                    <button @click="expanded = !expanded"
                                        class="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $approvalMode === 'ask' ? 'bg-amber-100 text-amber-700' : ($approvalMode === 'deny' ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700') }}">
                                        {{ \App\Domain\Tool\Enums\ToolApprovalMode::from($approvalMode)->label() }}
                                        <svg class="h-3 w-3 transition-transform" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                    </button>
                                </td>
                                <td class="px-6 py-4"><x-status-badge :status="$tool->status->value" /></td>
                            </tr>
                            <tr x-show="expanded" x-transition>
                                <td colspan="6" class="border-t border-gray-100 bg-gray-50 px-6 py-3">
                                    <div class="flex flex-wrap items-end gap-4"
                                        x-data="{
                                            mode: '{{ $approvalMode }}',
                                            timeout: {{ $approvalTimeout }},
                                            timeoutAction: '{{ $timeoutAction }}'
                                        }">
                                        <div>
                                            <label class="mb-1 block text-xs font-medium text-gray-600">Approval Mode</label>
                                            <select x-model="mode"
                                                class="rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                                                @foreach(\App\Domain\Tool\Enums\ToolApprovalMode::cases() as $m)
                                                    <option value="{{ $m->value }}">{{ $m->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <div x-show="mode === 'ask'">
                                            <label class="mb-1 block text-xs font-medium text-gray-600">Timeout (minutes)</label>
                                            <input x-model.number="timeout" type="number" min="1" max="1440"
                                                class="w-24 rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500" />
                                        </div>
                                        <div x-show="mode === 'ask'">
                                            <label class="mb-1 block text-xs font-medium text-gray-600">On Timeout</label>
                                            <select x-model="timeoutAction"
                                                class="rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                                                @foreach(\App\Domain\Tool\Enums\ApprovalTimeoutAction::cases() as $a)
                                                    <option value="{{ $a->value }}">{{ $a->label() }}</option>
                                                @endforeach
                                            </select>
                                        </div>
                                        <button
                                            @click="$wire.updateToolApproval('{{ $tool->id }}', mode, timeout, timeoutAction); expanded = false"
                                            class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                                            Save
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">No tools assigned. Tools enable the agent to take real-world actions.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>

        @elseif($activeTab === 'hooks')
            <div class="space-y-4">
                <div class="flex items-center justify-between">
                    <p class="text-sm text-gray-500">Lifecycle hooks run at specific points during agent execution. Class-level hooks (no agent) apply to all agents.</p>
                    <button wire:click="$set('showHookForm', true)" class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">
                        Add Hook
                    </button>
                </div>

                @if($showHookForm)
                    <div class="rounded-xl border border-gray-200 bg-white p-4 space-y-3">
                        <h3 class="text-sm font-semibold text-gray-900">{{ $editingHookId ? 'Edit' : 'New' }} Hook</h3>
                        <div class="grid grid-cols-2 gap-3">
                            <x-form-input wire:model="hookName" label="Name" placeholder="e.g. Always translate to German" />
                            <x-form-select wire:model="hookPosition" label="Position">
                                @foreach(\App\Domain\Agent\Enums\AgentHookPosition::cases() as $pos)
                                    <option value="{{ $pos->value }}">{{ $pos->label() }}</option>
                                @endforeach
                            </x-form-select>
                            <x-form-select wire:model="hookType" label="Type">
                                @foreach(\App\Domain\Agent\Enums\AgentHookType::cases() as $type)
                                    <option value="{{ $type->value }}">{{ $type->label() }}</option>
                                @endforeach
                            </x-form-select>
                            <x-form-input wire:model.number="hookPriority" label="Priority" type="number" hint="Lower runs first" />
                        </div>
                        <x-form-textarea wire:model="hookConfigJson" label="Config (JSON)" mono="true" hint="Hook-specific configuration" />
                        <div class="flex justify-end gap-2">
                            <button wire:click="$call('resetHookForm')" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                            <button wire:click="saveHook" class="rounded-lg bg-primary-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-primary-700">Save</button>
                        </div>
                    </div>
                @endif

                @if($hooks->isNotEmpty())
                    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Name</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Position</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Type</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Scope</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500">Priority</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($hooks as $hook)
                                    <tr class="{{ $hook->enabled ? '' : 'opacity-50' }}">
                                        <td class="px-4 py-2.5 text-sm font-medium text-gray-900">{{ $hook->name }}</td>
                                        <td class="px-4 py-2.5">
                                            <span class="inline-flex rounded-full bg-blue-50 px-2 py-0.5 text-xs font-medium text-blue-700">{{ $hook->position->label() }}</span>
                                        </td>
                                        <td class="px-4 py-2.5 text-sm text-gray-600">{{ $hook->type->label() }}</td>
                                        <td class="px-4 py-2.5">
                                            <span class="inline-flex rounded-full {{ $hook->isClassLevel() ? 'bg-amber-50 text-amber-700' : 'bg-green-50 text-green-700' }} px-2 py-0.5 text-xs font-medium">
                                                {{ $hook->isClassLevel() ? 'All Agents' : 'This Agent' }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-2.5 text-sm text-gray-500">{{ $hook->priority }}</td>
                                        <td class="px-4 py-2.5 text-right">
                                            <div class="flex items-center justify-end gap-1">
                                                <button wire:click="toggleHook('{{ $hook->id }}')" class="rounded p-1 text-gray-400 hover:text-gray-600" title="{{ $hook->enabled ? 'Disable' : 'Enable' }}">
                                                    @if($hook->enabled)
                                                        <i class="fa-solid fa-circle-check text-base text-green-500"></i>
                                                    @else
                                                        <i class="fa-solid fa-ban text-base"></i>
                                                    @endif
                                                </button>
                                                <button wire:click="editHook('{{ $hook->id }}')" class="rounded p-1 text-gray-400 hover:text-blue-600">
                                                    <i class="fa-solid fa-pen text-base"></i>
                                                </button>
                                                <button wire:click="deleteHook('{{ $hook->id }}')" wire:confirm="Delete this hook?" class="rounded p-1 text-gray-400 hover:text-red-600">
                                                    <i class="fa-solid fa-trash text-base"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <div class="rounded-xl border border-dashed border-gray-300 p-6 text-center text-sm text-gray-500">
                        No hooks configured. Add hooks to customize this agent's behavior at key lifecycle points.
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'executions')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Skills</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Cost</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Feedback</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($executions as $exec)
                            @php $existingFeedback = $feedbackByExecution[$exec->id] ?? null; @endphp
                            <tr>
                                <td class="px-6 py-4"><x-status-badge :status="$exec->status" /></td>
                                <td class="px-6 py-4 text-sm text-gray-500">
                                    @if($exec->tool_calls_count > 0)
                                        {{ $exec->tool_calls_count }} tool calls ({{ $exec->llm_steps_count }} steps)
                                    @else
                                        {{ count($exec->skills_executed ?? []) }} skills
                                    @endif
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $exec->duration_ms ? number_format($exec->duration_ms) . 'ms' : '-' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $exec->cost_credits }} credits</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $exec->created_at->diffForHumans() }}</td>
                                <td class="px-6 py-4">
                                    <div x-data="{ open: false, comment: '' }">
                                        <div class="flex items-center gap-2">
                                            {{-- Thumbs up --}}
                                            <button
                                                wire:click="submitFeedback('{{ $exec->id }}', 1)"
                                                class="rounded p-1 transition-colors {{ $existingFeedback && $existingFeedback->score === 1 ? 'text-green-600 bg-green-50' : 'text-gray-400 hover:text-green-600 hover:bg-green-50' }}"
                                                title="Good output"
                                            >
                                                <i class="{{ $existingFeedback && $existingFeedback->score === 1 ? 'fa-solid' : 'fa-regular' }} fa-thumbs-up text-base"></i>
                                            </button>
                                            {{-- Thumbs down (opens comment box) --}}
                                            <button
                                                @click="open = !open"
                                                class="rounded p-1 transition-colors {{ $existingFeedback && $existingFeedback->score === -1 ? 'text-red-600 bg-red-50' : 'text-gray-400 hover:text-red-600 hover:bg-red-50' }}"
                                                title="Bad output — add correction"
                                            >
                                                <i class="{{ $existingFeedback && $existingFeedback->score === -1 ? 'fa-solid' : 'fa-regular' }} fa-thumbs-down text-base"></i>
                                            </button>
                                        </div>
                                        {{-- Inline correction form --}}
                                        <div x-show="open" x-transition class="mt-2 w-56">
                                            <textarea
                                                x-model="comment"
                                                rows="2"
                                                placeholder="What went wrong? (optional)"
                                                class="block w-full rounded border border-gray-300 px-2 py-1 text-xs text-gray-700 focus:border-primary-500 focus:ring-primary-500"
                                            ></textarea>
                                            <div class="mt-1 flex gap-1">
                                                <button
                                                    @click="$wire.submitFeedback('{{ $exec->id }}', -1, comment); open = false; comment = ''"
                                                    class="rounded bg-red-600 px-2 py-1 text-xs text-white hover:bg-red-700"
                                                >Submit</button>
                                                <button
                                                    @click="open = false; comment = ''"
                                                    class="rounded bg-gray-100 px-2 py-1 text-xs text-gray-600 hover:bg-gray-200"
                                                >Cancel</button>
                                            </div>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-400">No executions yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
            </div>
        @elseif($activeTab === 'history')
            <div class="space-y-4">
                @if($runtimeState)
                    <div class="grid grid-cols-4 gap-4">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-2xl font-bold text-gray-900">{{ number_format($runtimeState->total_executions) }}</div>
                            <div class="text-sm text-gray-500">Total Executions</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-2xl font-bold text-gray-900">{{ number_format($runtimeState->total_input_tokens) }}</div>
                            <div class="text-sm text-gray-500">Input Tokens</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-2xl font-bold text-gray-900">{{ number_format($runtimeState->total_output_tokens) }}</div>
                            <div class="text-sm text-gray-500">Output Tokens</div>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <div class="text-2xl font-bold text-gray-900">{{ number_format($runtimeState->total_cost_credits) }}</div>
                            <div class="text-sm text-gray-500">Lifetime Credits</div>
                        </div>
                    </div>
                    @if($runtimeState->last_error)
                        <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                            <h3 class="mb-1 text-sm font-semibold text-red-800">Last Error</h3>
                            <p class="text-sm text-red-700">{{ $runtimeState->last_error }}</p>
                        </div>
                    @endif
                @endif

                <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-6 py-4">
                        <h3 class="text-sm font-semibold text-gray-700">Configuration History</h3>
                        <p class="mt-0.5 text-xs text-gray-400">{{ $revisions->count() }} revisions (last 20)</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Date</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Source</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Changed Fields</th>
                                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Notes</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200">
                                @forelse($revisions as $revision)
                                    <tr class="{{ $revision->source === 'rollback' ? 'bg-amber-50' : '' }}">
                                        <td class="px-6 py-4 text-sm text-gray-500 whitespace-nowrap">{{ $revision->created_at->diffForHumans() }}</td>
                                        <td class="px-6 py-4">
                                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                                {{ $revision->source === 'rollback' ? 'bg-amber-100 text-amber-700' : ($revision->source === 'mcp' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-700') }}">
                                                {{ $revision->source }}
                                            </span>
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-600">
                                            {{ implode(', ', $revision->changed_keys ?? []) ?: '—' }}
                                        </td>
                                        <td class="px-6 py-4 text-sm text-gray-500">{{ $revision->notes ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-400">No configuration changes recorded yet</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

        @elseif($activeTab === 'risk')
            @php
                $riskScore = (float) ($agent->risk_score ?? 0);
                $riskColor = $riskScore > 60 ? 'red' : ($riskScore > 40 ? 'yellow' : 'green');
                $riskLabel = $riskScore > 60 ? 'High' : ($riskScore > 40 ? 'Medium' : 'Low');
                $profile = $agent->risk_profile ?? [];
                $riskFactors = $profile['risk_factors'] ?? [];
            @endphp
            <div class="space-y-4">
                {{-- Score card --}}
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-700">Overall Risk Score</h3>
                            @if($agent->risk_profile_updated_at)
                                <p class="mt-0.5 text-xs text-gray-400">Last updated {{ $agent->risk_profile_updated_at->diffForHumans() }}</p>
                            @else
                                <p class="mt-0.5 text-xs text-gray-400">Not yet computed — run health check to calculate</p>
                            @endif
                        </div>
                        <div class="text-right">
                            <span class="text-4xl font-bold {{ $riskColor === 'red' ? 'text-red-600' : ($riskColor === 'yellow' ? 'text-yellow-600' : 'text-green-600') }}">
                                {{ $agent->risk_score !== null ? number_format($riskScore, 0) : '—' }}
                            </span>
                            @if($agent->risk_score !== null)
                                <span class="ml-1 text-lg text-gray-400">/ 100</span>
                                <div class="mt-1">
                                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                        {{ $riskColor === 'red' ? 'bg-red-100 text-red-700' : ($riskColor === 'yellow' ? 'bg-yellow-100 text-yellow-700' : 'bg-green-100 text-green-700') }}">
                                        {{ $riskLabel }} Risk
                                    </span>
                                </div>
                            @endif
                        </div>
                    </div>

                    {{-- Score bar --}}
                    @if($agent->risk_score !== null)
                        <div class="mt-4">
                            <div class="h-3 w-full rounded-full bg-gray-100">
                                <div class="h-3 rounded-full transition-all
                                    {{ $riskColor === 'red' ? 'bg-red-500' : ($riskColor === 'yellow' ? 'bg-yellow-500' : 'bg-green-500') }}"
                                    style="width: {{ min(100, $riskScore) }}%"></div>
                            </div>
                            <div class="mt-1 flex justify-between text-xs text-gray-400">
                                <span>Low (0)</span>
                                <span>Medium (40)</span>
                                <span>High (60)</span>
                                <span>Critical (80+)</span>
                            </div>
                        </div>
                    @endif
                </div>

                @if(!empty($riskFactors))
                    {{-- Risk factors --}}
                    <div class="rounded-xl border border-red-100 bg-red-50 p-4">
                        <h3 class="mb-2 text-sm font-semibold text-red-800">Active Risk Factors</h3>
                        <ul class="space-y-1">
                            @foreach($riskFactors as $factor)
                                @php
                                    $factorLabels = [
                                        'high_failure_rate' => 'High failure rate in the last 7 days',
                                        'high_cost' => 'Cost in top 20% of team agents',
                                        'high_cost_variance' => 'Highly unpredictable cost (CV > 50%)',
                                        'frequent_guardrail_blocks' => 'Frequent guardrail blocks (> 10%)',
                                        'pii_exposure_risk' => 'PII exposure incidents detected',
                                    ];
                                @endphp
                                <li class="flex items-center gap-2 text-sm text-red-700">
                                    <i class="fa-solid fa-triangle-exclamation text-base flex-shrink-0 text-red-500"></i>
                                    {{ $factorLabels[$factor] ?? ucwords(str_replace('_', ' ', $factor)) }}
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @elseif($agent->risk_score !== null)
                    <div class="rounded-xl border border-green-100 bg-green-50 p-4">
                        <p class="text-sm text-green-700">No active risk factors. Agent is operating within normal parameters.</p>
                    </div>
                @endif

                {{-- Breakdown metrics --}}
                @if(!empty($profile))
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <h3 class="mb-3 text-sm font-semibold text-gray-700">7-Day Metrics</h3>
                            <dl class="space-y-2">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500">Failure Rate</dt>
                                    <dd class="font-medium text-gray-900">{{ number_format(($profile['failure_rate_7d'] ?? 0) * 100, 1) }}%</dd>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500">Avg Cost / Run</dt>
                                    <dd class="font-medium text-gray-900">{{ number_format($profile['avg_cost_per_run'] ?? 0) }} credits</dd>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500">Cost Volatility</dt>
                                    <dd class="font-medium {{ ($profile['cost_volatility'] ?? 'low') === 'high' ? 'text-red-600' : (($profile['cost_volatility'] ?? 'low') === 'medium' ? 'text-yellow-600' : 'text-green-600') }}">
                                        {{ ucfirst($profile['cost_volatility'] ?? 'low') }}
                                    </dd>
                                </div>
                            </dl>
                        </div>
                        <div class="rounded-xl border border-gray-200 bg-white p-4">
                            <h3 class="mb-3 text-sm font-semibold text-gray-700">Safety Metrics</h3>
                            <dl class="space-y-2">
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500">Guardrail Block Rate</dt>
                                    <dd class="font-medium text-gray-900">{{ number_format(($profile['guardrail_block_rate'] ?? 0) * 100, 1) }}%</dd>
                                </div>
                                <div class="flex justify-between text-sm">
                                    <dt class="text-gray-500">PII Detection Rate</dt>
                                    <dd class="font-medium text-gray-900">{{ number_format(($profile['pii_detection_rate'] ?? 0) * 100, 1) }}%</dd>
                                </div>
                            </dl>
                        </div>
                    </div>

                    {{-- Score formula breakdown --}}
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">Score Breakdown</h3>
                        <div class="space-y-2">
                            @php
                                $components = [
                                    ['Failure Rate (×30)', ($profile['failure_rate_7d'] ?? 0) * 30],
                                    ['Cost Percentile (×25)', 0],  // not stored separately, shown as reference
                                    ['PII Rate (×25)', ($profile['pii_detection_rate'] ?? 0) * 25],
                                    ['Guardrail Blocks (×20)', ($profile['guardrail_block_rate'] ?? 0) * 20],
                                ];
                            @endphp
                            @foreach($components as [$label, $value])
                                <div class="flex items-center gap-3 text-sm">
                                    <span class="w-44 text-gray-500">{{ $label }}</span>
                                    <div class="flex-1 rounded-full bg-gray-100 h-2">
                                        <div class="h-2 rounded-full bg-primary-400" style="width: {{ min(100, $value) }}%"></div>
                                    </div>
                                    <span class="w-12 text-right font-mono text-xs text-gray-700">{{ number_format($value, 1) }}</span>
                                </div>
                            @endforeach
                        </div>
                        <p class="mt-3 text-xs text-gray-400">Formula: failure_rate×30 + cost_percentile×25 + pii_rate×25 + guardrail_block_rate×20 = total risk score (0–100)</p>
                    </div>
                @endif
            </div>

        @elseif($activeTab === 'evolution')
            <div class="rounded-xl border border-gray-200 bg-white p-6">
                <livewire:evolution.evolution-proposal-panel :agent="$agent" />
            </div>

        @elseif($activeTab === 'heartbeat')
            {{-- Heartbeat Scheduling --}}
            <div class="space-y-4">
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <div class="mb-4 flex items-center justify-between">
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">Heartbeat Schedule</h3>
                            <p class="mt-0.5 text-xs text-gray-500">A recurring task the agent runs automatically on a cron schedule. Evaluated every minute by the scheduler.</p>
                        </div>
                        @if(!empty($agent->heartbeat_definition))
                            <div class="flex items-center gap-2">
                                <span class="text-xs {{ ($agent->heartbeat_definition['enabled'] ?? false) ? 'text-green-700 bg-green-100' : 'text-gray-600 bg-gray-100' }} rounded-full px-2.5 py-1 font-medium">
                                    {{ ($agent->heartbeat_definition['enabled'] ?? false) ? 'Enabled' : 'Disabled' }}
                                </span>
                                <button wire:click="toggleHeartbeat"
                                    class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-600 hover:bg-gray-50">
                                    {{ ($agent->heartbeat_definition['enabled'] ?? false) ? 'Disable' : 'Enable' }}
                                </button>
                                <button wire:click="runHeartbeatNow"
                                    wire:confirm="Run the heartbeat task immediately?"
                                    class="rounded-lg bg-primary-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-primary-700">
                                    Run Now
                                </button>
                            </div>
                        @endif
                    </div>

                    @if(!empty($agent->heartbeat_definition))
                        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                <dt class="mb-1 text-xs font-medium text-gray-500">Cron Expression</dt>
                                <dd class="font-mono text-sm text-gray-900">{{ $agent->heartbeat_definition['cron'] ?? '—' }}</dd>
                            </div>
                            <div class="rounded-lg border border-gray-100 bg-gray-50 p-3">
                                <dt class="mb-1 text-xs font-medium text-gray-500">Next Run At</dt>
                                <dd class="text-sm text-gray-900">
                                    @if(!empty($agent->heartbeat_definition['next_run_at']))
                                        {{ \Illuminate\Support\Carbon::parse($agent->heartbeat_definition['next_run_at'])->diffForHumans() }}
                                        <span class="text-xs text-gray-400">({{ $agent->heartbeat_definition['next_run_at'] }})</span>
                                    @else
                                        <span class="text-gray-400">Pending first run</span>
                                    @endif
                                </dd>
                            </div>
                            <div class="col-span-full rounded-lg border border-gray-100 bg-gray-50 p-3">
                                <dt class="mb-1 text-xs font-medium text-gray-500">Prompt</dt>
                                <dd class="whitespace-pre-wrap text-sm text-gray-900">{{ $agent->heartbeat_definition['prompt'] ?? '—' }}</dd>
                            </div>
                        </dl>
                        <p class="mt-4 text-xs text-gray-400">To change the schedule, use the MCP tool <code class="font-mono">agent_heartbeat_update</code> or update the agent's <code class="font-mono">heartbeat_definition</code> JSONB field directly.</p>
                    @else
                        <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center">
                            <p class="text-sm text-gray-500">No heartbeat configured for this agent.</p>
                            <p class="mt-1 text-xs text-gray-400">Use the MCP tool <code class="font-mono">agent_heartbeat_update</code> to set a schedule.</p>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    @endif

    {{-- Plugin extension point: inject custom content into agent detail --}}
    @stack('fleet.agent.detail')
</div>
