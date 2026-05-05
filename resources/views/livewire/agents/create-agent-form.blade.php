<div class="mx-auto max-w-3xl">
    <div class="rounded-xl border border-gray-200 bg-white p-6">
        <div class="space-y-4">
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form-input wire:model="name" label="Name" type="text" placeholder="e.g. Research Assistant"
                    :error="$errors->first('name')"
                    toolparamdescription="Agent name — unique, descriptive identifier" />
                <x-form-input wire:model="role" label="Role" type="text" placeholder="e.g. Lead Research Analyst"
                    :error="$errors->first('role')"
                    toolparamdescription="Agent role — defines the agent's job function (e.g., Content Writer, Data Analyst)" />
            </div>

            <x-form-textarea wire:model="goal" label="Goal" rows="2" placeholder="What should this agent accomplish?"
                :error="$errors->first('goal')"
                toolparamdescription="What the agent should achieve when executed" />

            <x-form-textarea wire:model="backstory" label="Backstory (optional)" rows="3" placeholder="Background context for the agent..."
                toolparamdescription="Background context that shapes the agent's behavior and expertise" />

            {{-- Behavioral Constraint Rules --}}
            <div
                x-data="{
                    templates: @js(collect(config('agent-constraint-templates', []))->map(fn($t) => ['slug' => $t['slug'], 'name' => $t['name'], 'rules' => $t['rules']])->values()->toArray()),
                    selectedSlug: '',
                    applyTemplate() {
                        if (!this.selectedSlug) return;
                        const tpl = this.templates.find(t => t.slug === this.selectedSlug);
                        if (tpl) {
                            $wire.set('personalityBehavioralRules', tpl.rules.join('\n'));
                        }
                    }
                }"
            >
                <div class="mb-2 flex items-center justify-between">
                    <label class="block text-sm font-medium text-gray-700">Behavioral Rules (optional)</label>
                    <div class="flex items-center gap-2">
                        <select
                            x-model="selectedSlug"
                            x-on:change="applyTemplate()"
                            class="rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500"
                        >
                            <option value="">Load constraint template…</option>
                            <template x-for="tpl in templates" :key="tpl.slug">
                                <option :value="tpl.slug" x-text="tpl.name"></option>
                            </template>
                        </select>
                    </div>
                </div>
                <x-form-textarea
                    wire:model="personalityBehavioralRules"
                    rows="4"
                    placeholder="One rule per line. E.g.: Always cite sources when making factual claims."
                    hint="Rules injected into the agent's system prompt. Use a template above or write your own."
                />
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-form-select wire:model.live="provider" label="Provider" toolparamdescription="LLM provider: anthropic, openai, or google">
                    @foreach($providers as $key => $provider)
                        <option value="{{ $key }}">{{ $provider['name'] }}</option>
                    @endforeach
                </x-form-select>

                <x-form-select wire:model="model" label="Model" toolparamdescription="LLM model to use (depends on selected provider)">
                    @if(isset($providers[$this->provider]['models']))
                        @foreach($providers[$this->provider]['models'] as $modelKey => $modelConfig)
                            <option value="{{ $modelKey }}">{{ $modelConfig['label'] }}</option>
                        @endforeach
                    @endif
                </x-form-select>

                <x-form-input wire:model.number="budgetCapCredits" label="Budget Cap (credits)" type="number" min="0" placeholder="Leave empty for unlimited" />
            </div>

            @if($supportsMaxCreditsPerCall)
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form-input wire:model.number="maxCreditsPerCall" label="Max credits per call (override)" type="number" min="1"
                    hint="Per-agent cap that overrides the team default for a single LLM call. Leave empty to inherit team / platform setting." />
            </div>
            @endif

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
                <x-form-select wire:model="executionTier" label="Execution Tier">
                    @foreach(\App\Domain\Agent\Enums\ExecutionTier::cases() as $tier)
                        <option value="{{ $tier->value }}">{{ $tier->label() }}</option>
                    @endforeach
                </x-form-select>

                <x-form-select wire:model="reasoningStrategy" label="Reasoning Strategy" hint="Shapes how the agent thinks before acting">
                    @foreach(\App\Domain\Agent\Enums\AgentReasoningStrategy::cases() as $strategy)
                        <option value="{{ $strategy->value }}">{{ $strategy->label() }}</option>
                    @endforeach
                </x-form-select>

                <x-form-select wire:model="toolProfile" label="Tool Profile" hint="Restricts which tools this agent can access">
                    <option value="">No restriction (all tools)</option>
                    @foreach(config('tool_profiles.profiles', []) as $key => $profile)
                        <option value="{{ $key }}">{{ $profile['label'] }} — {{ $profile['description'] }}</option>
                    @endforeach
                </x-form-select>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form-select wire:model="reasoningEffort" label="Reasoning Effort" hint="Extended thinking budget for the model. Anthropic Claude only; ignored on OpenAI and Google.">
                    @foreach(\App\Infrastructure\AI\Enums\ReasoningEffort::cases() as $effort)
                        <option value="{{ $effort->value }}">{{ $effort->label() }}</option>
                    @endforeach
                </x-form-select>

                <x-form-select wire:model="environment" label="Environment" hint="Preset that auto-attaches a tool bundle">
                    <option value="">No preset</option>
                    @foreach(\App\Domain\Agent\Enums\AgentEnvironment::cases() as $env)
                        <option value="{{ $env->value }}">{{ $env->label() }}</option>
                    @endforeach
                </x-form-select>
            </div>

            <div class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                <label class="flex items-center gap-2 text-sm font-medium text-gray-700">
                    <input type="checkbox" wire:model.live="useToolSearch" class="rounded border-gray-300 text-primary-600 focus:ring-primary-500" />
                    Enable Tool Search
                </label>
                <p class="mt-1 text-xs text-gray-500">Auto-discover relevant tools from the team pool by matching the user prompt against tool descriptions.</p>
                @if($useToolSearch)
                    <div class="mt-3">
                        <x-form-input wire:model="toolSearchTopK" label="Top K" type="number" min="1" max="20" hint="Maximum tools surfaced per agent invocation (1–20)." />
                    </div>
                @endif
            </div>

            @if(!empty($providers[$this->provider]['local']))
                <div class="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
                    Local agent — executes on the host machine using its own CLI process. No per-request API costs.
                </div>
            @endif

            {{-- Fallback Chain --}}
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Fallback Chain</label>
                <p class="mb-3 text-xs text-gray-500">If the primary provider fails or is rate-limited, requests fall through to fallbacks in order.</p>

                @foreach($fallbackChain as $index => $fallback)
                    <div class="mb-2 flex items-center gap-2" wire:key="fallback-{{ $index }}">
                        <span class="text-xs font-medium text-gray-400 w-6">{{ $index + 1 }}.</span>
                        <select wire:model.live="fallbackChain.{{ $index }}.provider" class="rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                            @foreach($providers as $key => $p)
                                <option value="{{ $key }}">{{ $p['name'] }}</option>
                            @endforeach
                        </select>
                        <select wire:model="fallbackChain.{{ $index }}.model" class="flex-1 rounded-lg border border-gray-300 py-1.5 px-3 text-sm focus:border-primary-500 focus:ring-primary-500">
                            @foreach($providers[$fallbackChain[$index]['provider'] ?? 'anthropic']['models'] ?? [] as $modelKey => $modelInfo)
                                <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                            @endforeach
                        </select>
                        <button wire:click="removeFallback({{ $index }})" type="button" class="rounded p-1 text-red-500 hover:bg-red-50">
                            <i class="fa-solid fa-xmark text-base"></i>
                        </button>
                    </div>
                @endforeach

                <button wire:click="addFallback" type="button" class="mt-1 rounded-lg border border-dashed border-gray-300 px-3 py-1.5 text-xs font-medium text-gray-500 hover:border-gray-400 hover:text-gray-700">
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
                            selected: $wire.entangle('selectedSkillIds'),
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
                    <p class="text-sm text-gray-400">No active skills available. <a href="{{ route('skills.create') }}" class="text-primary-600 hover:underline">Create a skill first.</a></p>
                @endif
            </div>

            {{-- Tool Federation --}}
            <div class="rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-900">Tool Federation</p>
                        <p class="text-xs text-gray-500 mt-0.5">Allow this agent to dynamically access all active team tools without explicit assignment</p>
                    </div>
                    <x-form-checkbox name="useFederation" wire:model.live="useFederation" />
                </div>
                @if($useFederation)
                    <div class="mt-3">
                        <x-form-select name="federationGroupId" wire:model="federationGroupId" label="Limit to federation group (optional)">
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
                    <x-form-checkbox name="useMemory" wire:model.live="useMemory" />
                </div>
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-900">Enable Scout Phase</p>
                        <p class="text-xs text-gray-500 mt-0.5">Run a cheap pre-execution LLM call to generate targeted memory retrieval queries before the main execution</p>
                    </div>
                    <x-form-checkbox name="enableScoutPhase" wire:model.live="enableScoutPhase" />
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
                            selected: $wire.entangle('selectedToolIds'),
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

            {{-- Linked Git Repositories --}}
            @if($availableGitRepositories->isNotEmpty())
            <div>
                <label class="mb-2 block text-sm font-medium text-gray-700">Linked Git Repositories</label>
                <p class="mb-3 text-xs text-gray-500">Linked repos inject a repo map into the agent's context at execution time.</p>
                <div class="grid grid-cols-1 gap-1 sm:grid-cols-2">
                    @foreach($availableGitRepositories as $repo)
                        <button type="button" wire:click="toggleGitRepository('{{ $repo->id }}')"
                            class="flex items-center gap-2 rounded-lg border px-3 py-2 text-left text-sm transition-colors
                                {{ in_array($repo->id, $gitRepositoryIds) ? 'border-primary-300 bg-primary-50 text-primary-800' : 'border-gray-200 bg-white text-gray-700 hover:border-primary-200 hover:bg-primary-50/40' }}">
                            <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded border {{ in_array($repo->id, $gitRepositoryIds) ? 'border-primary-500 bg-primary-500' : 'border-gray-300' }}">
                                @if(in_array($repo->id, $gitRepositoryIds))
                                    <i class="fa-solid fa-check text-xs text-white"></i>
                                @endif
                            </span>
                            <span class="min-w-0 truncate">{{ $repo->name }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            @endif

            {{-- Knowledge Base --}}
            @if($availableKnowledgeBases->isNotEmpty())
            <div>
                <x-form-select wire:model="knowledgeBaseId" label="Knowledge Base" hint="Link this agent to a knowledge base for RAG-powered context">
                    <option value="">None</option>
                    @foreach($availableKnowledgeBases as $kb)
                        <option value="{{ $kb->id }}">{{ $kb->name }}</option>
                    @endforeach
                </x-form-select>
            </div>
            @endif

            {{-- A/B Evaluation --}}
            <div class="rounded-lg border border-gray-200 p-4">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-sm font-medium text-gray-900">A/B Evaluation</p>
                        <p class="text-xs text-gray-500 mt-0.5">Enable A/B testing to compare this agent's performance against variants</p>
                    </div>
                    <x-form-checkbox name="evaluationEnabled" wire:model.live="evaluationEnabled" />
                </div>
                @if($evaluationEnabled)
                    <div class="mt-3">
                        <x-form-input wire:model.number="evaluationSampleRate" label="Sample Rate" type="number" min="0" max="1" step="0.01"
                            placeholder="0.0 - 1.0" hint="Fraction of requests to include in the evaluation (e.g. 0.1 = 10%)" />
                    </div>
                @endif
            </div>

            {{-- Heartbeat Definition (optional advanced field) --}}
            <div>
                <x-form-textarea
                    wire:model="heartbeatJson"
                    label="Heartbeat Definition (optional)"
                    rows="4"
                    :mono="true"
                    placeholder='{"enabled":true,"cron":"0 * * * *","prompt":"Perform your scheduled check-in task."}'
                    hint="JSON defining the agent's recurring autonomous task schedule. Leave blank to configure later."
                    :error="$errors->first('heartbeatJson')"
                />
            </div>

            <div class="flex justify-end border-t border-gray-200 pt-4">
                <button wire:click="save" class="rounded-lg bg-primary-600 px-6 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    Create Agent
                </button>
            </div>
        </div>
    </div>
</div>

@script
<script>
if (window.FleetQWebMcp?.isAvailable()) {
    window.FleetQWebMcp.registerTool({
        name: 'create_agent',
        description: 'Create a new AI agent with role, goal, and LLM configuration',
        inputSchema: {
            type: 'object',
            properties: {
                name: { type: 'string', description: 'Agent name — unique, descriptive identifier' },
                role: { type: 'string', description: 'Agent role — defines the job function (e.g., Content Writer)' },
                goal: { type: 'string', description: 'What the agent should achieve when executed' },
                backstory: { type: 'string', description: 'Background context that shapes behavior (optional)' },
                provider: { type: 'string', description: 'LLM provider: anthropic, openai, or google' },
                model: { type: 'string', description: 'LLM model ID (depends on provider)' },
                budget_cap_credits: { type: 'number', description: 'Budget cap in credits (optional)' },
            },
            required: ['name', 'role', 'goal'],
        },
        async execute(params) {
            $wire.set('name', params.name);
            $wire.set('role', params.role);
            $wire.set('goal', params.goal);
            if (params.backstory) $wire.set('backstory', params.backstory);
            if (params.provider) $wire.set('provider', params.provider);
            if (params.model) $wire.set('model', params.model);
            if (params.budget_cap_credits) $wire.set('budgetCapCredits', params.budget_cap_credits);
            await $wire.save();
            return { success: true, message: 'Agent created' };
        },
    });
}
</script>
@endscript
