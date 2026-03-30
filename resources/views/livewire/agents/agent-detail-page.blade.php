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
            <svg class="h-5 w-5 shrink-0 text-yellow-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
            </svg>
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
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
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
                                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
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
                                                <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
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
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <div class="flex items-center gap-1.5">
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700"
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
                <a href="{{ route('agents.voice', $agent) }}" class="ml-1 inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-1.5 text-sm font-medium text-gray-600 hover:bg-gray-50" title="Voice Session">
                    <i class="fa-solid fa-microphone h-4 w-4"></i>
                    Voice
                </a>
                <button wire:click="startEdit" class="ml-1 rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Edit Agent">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
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
                @foreach(['overview' => 'Overview', 'skills' => 'Skills', 'tools' => 'Tools', 'executions' => 'Executions', 'history' => 'Config History', 'risk' => 'Risk Profile', 'evolution' => 'Evolution', 'heartbeat' => 'Heartbeat'] as $tab => $label)
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
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Tool</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Functions</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Priority</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($tools as $tool)
                            <tr>
                                <td class="px-6 py-4">
                                    <a href="{{ route('tools.show', $tool) }}" class="font-medium text-primary-600 hover:text-primary-800">{{ $tool->name }}</a>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->type->label() }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->functionCount() }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->pivot->priority }}</td>
                                <td class="px-6 py-4"><x-status-badge :status="$tool->status->value" /></td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">No tools assigned. Tools enable the agent to take real-world actions.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
                </div>
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
                                                <svg class="h-4 w-4" fill="{{ $existingFeedback && $existingFeedback->score === 1 ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 10h4.764a2 2 0 011.789 2.894l-3.5 7A2 2 0 0115.263 21h-4.017c-.163 0-.326-.02-.485-.06L7 20m7-10V5a2 2 0 00-2-2h-.095c-.5 0-.905.405-.905.905 0 .714-.211 1.412-.608 2.006L7 11v9m7-10h-2M7 20H5a2 2 0 01-2-2v-6a2 2 0 012-2h2.5" />
                                                </svg>
                                            </button>
                                            {{-- Thumbs down (opens comment box) --}}
                                            <button
                                                @click="open = !open"
                                                class="rounded p-1 transition-colors {{ $existingFeedback && $existingFeedback->score === -1 ? 'text-red-600 bg-red-50' : 'text-gray-400 hover:text-red-600 hover:bg-red-50' }}"
                                                title="Bad output — add correction"
                                            >
                                                <svg class="h-4 w-4" fill="{{ $existingFeedback && $existingFeedback->score === -1 ? 'currentColor' : 'none' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 14H5.236a2 2 0 01-1.789-2.894l3.5-7A2 2 0 018.736 3h4.018c.163 0 .326.02.485.06L17 4m-7 10v2a2 2 0 002 2h.095c.5 0 .905-.405.905-.905 0-.714.211-1.412.608-2.006L17 13V4m-7 10h2m5-10h2a2 2 0 012 2v6a2 2 0 01-2 2h-2.5" />
                                                </svg>
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
                                    <svg class="h-4 w-4 flex-shrink-0 text-red-500" fill="currentColor" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd" />
                                    </svg>
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
