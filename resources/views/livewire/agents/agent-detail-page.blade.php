<div>
    {{-- Flash message --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">
            {{ session('message') }}
        </div>
    @endif

    @if($editing)
        {{-- ====== EDIT MODE ====== --}}
        <div class="rounded-xl border border-primary-200 bg-white p-6">
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Edit Agent</h3>

            <div class="space-y-4">
                {{-- Name & Role --}}
                <div class="grid grid-cols-2 gap-4">
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
                <div class="grid grid-cols-3 gap-4">
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
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($availableSkills as $skill)
                                <button wire:click="toggleSkill('{{ $skill->id }}')" type="button"
                                    class="flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition
                                        {{ in_array($skill->id, $editSkillIds) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                                    <div class="flex h-5 w-5 items-center justify-center rounded border {{ in_array($skill->id, $editSkillIds) ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-300' }}">
                                        @if(in_array($skill->id, $editSkillIds))
                                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $skill->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $skill->type->label() }}</div>
                                    </div>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No active skills available.</p>
                    @endif
                </div>

                {{-- Tool Assignment --}}
                <div>
                    <label class="mb-2 block text-sm font-medium text-gray-700">Assign Tools</label>
                    <p class="mb-3 text-xs text-gray-500">When tools are assigned, the agent uses an agentic loop where the LLM decides which tools to call.</p>
                    @if($availableTools->isNotEmpty())
                        <div class="grid grid-cols-2 gap-2">
                            @foreach($availableTools as $tool)
                                <button wire:click="toggleTool('{{ $tool->id }}')" type="button"
                                    class="flex items-center gap-2 rounded-lg border p-3 text-left text-sm transition
                                        {{ in_array($tool->id, $editToolIds) ? 'border-primary-500 bg-primary-50' : 'border-gray-200 hover:border-gray-300' }}">
                                    <div class="flex h-5 w-5 items-center justify-center rounded border {{ in_array($tool->id, $editToolIds) ? 'border-primary-500 bg-primary-500 text-white' : 'border-gray-300' }}">
                                        @if(in_array($tool->id, $editToolIds))
                                            <svg class="h-3 w-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd" /></svg>
                                        @endif
                                    </div>
                                    <div>
                                        <div class="font-medium">{{ $tool->name }}</div>
                                        <div class="text-xs text-gray-500">{{ $tool->type->label() }}</div>
                                    </div>
                                </button>
                            @endforeach
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
        <div class="mb-6 flex items-center justify-between">
            <div>
                <div class="flex items-center gap-3">
                    <h2 class="text-xl font-semibold text-gray-900">{{ $agent->name }}</h2>
                    <x-status-badge :status="$agent->status->value" />
                </div>
                @if($agent->role)
                    <p class="mt-1 text-sm font-medium text-gray-600">{{ $agent->role }}</p>
                @endif
                @if($agent->goal)
                    <p class="mt-0.5 text-sm text-gray-500">{{ $agent->goal }}</p>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <div class="flex items-center gap-1.5">
                    <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                        {{ $agent->provider }}/{{ $agent->model }}
                    </span>
                    @foreach($agent->config['fallback_chain'] ?? [] as $fb)
                        <span class="text-xs text-gray-400">&rarr;</span>
                        <span class="inline-flex items-center rounded-full bg-gray-50 px-2 py-0.5 text-xs text-gray-500">
                            {{ $fb['provider'] }}/{{ $fb['model'] }}
                        </span>
                    @endforeach
                </div>
                <button wire:click="startEdit" class="ml-1 rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Edit Agent">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <button wire:click="toggleStatus"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium {{ $agent->status === \App\Domain\Agent\Enums\AgentStatus::Active ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-green-300 text-green-700 hover:bg-green-50' }}">
                    {{ $agent->status === \App\Domain\Agent\Enums\AgentStatus::Active ? 'Disable' : 'Enable' }}
                </button>
            </div>
        </div>

        {{-- Stats --}}
        <div class="mb-6 grid grid-cols-5 gap-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $skills->count() }}</div>
                <div class="text-sm text-gray-500">Skills</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $tools->count() }}</div>
                <div class="text-sm text-gray-500">Tools</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $executions->count() }}</div>
                <div class="text-sm text-gray-500">Recent Executions</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ number_format($agent->budget_spent_credits) }}</div>
                <div class="text-sm text-gray-500">Credits Spent</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $agent->budget_cap_credits ? number_format($agent->budgetRemainingCredits()) : 'Unlimited' }}</div>
                <div class="text-sm text-gray-500">Budget Remaining</div>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="mb-4 border-b border-gray-200">
            <nav class="-mb-px flex space-x-8">
                @foreach(['overview' => 'Overview', 'skills' => 'Skills', 'tools' => 'Tools', 'executions' => 'Executions'] as $tab => $label)
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

                <div class="grid grid-cols-2 gap-4">
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-2 text-sm font-semibold text-gray-700">Capabilities</h3>
                        <pre class="max-h-32 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($agent->capabilities ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-2 text-sm font-semibold text-gray-700">Constraints</h3>
                        <pre class="max-h-32 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($agent->constraints ?? [], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                    </div>
                </div>
            </div>

        @elseif($activeTab === 'skills')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
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

        @elseif($activeTab === 'tools')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
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

        @elseif($activeTab === 'executions')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Skills</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Cost</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($executions as $exec)
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
                            </tr>
                        @empty
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-400">No executions yet</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @endif
    @endif
</div>
