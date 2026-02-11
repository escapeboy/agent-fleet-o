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
            <h3 class="mb-4 text-lg font-semibold text-gray-900">Edit Skill</h3>

            <div class="space-y-4">
                {{-- Name & Type --}}
                <div class="grid grid-cols-2 gap-4">
                    <x-form-input wire:model="editName" label="Name" type="text"
                        :error="$errors->first('editName')" />
                    <x-form-select wire:model="editType" label="Type">
                        @foreach(\App\Domain\Skill\Enums\SkillType::cases() as $t)
                            <option value="{{ $t->value }}">{{ $t->label() }}</option>
                        @endforeach
                    </x-form-select>
                </div>

                {{-- Description --}}
                <x-form-textarea wire:model="editDescription" label="Description" rows="2"
                    :error="$errors->first('editDescription')" />

                {{-- Risk Level --}}
                <x-form-select wire:model="editRiskLevel" label="Risk Level">
                    @foreach(\App\Domain\Skill\Enums\RiskLevel::cases() as $rl)
                        <option value="{{ $rl->value }}">{{ ucfirst($rl->value) }}</option>
                    @endforeach
                </x-form-select>

                {{-- Provider / Model --}}
                <div class="grid grid-cols-3 gap-4">
                    <x-form-select wire:model.live="editProvider" label="Provider">
                        <option value="">Platform default</option>
                        @foreach($providers as $key => $p)
                            <option value="{{ $key }}">{{ $p['name'] }}</option>
                        @endforeach
                    </x-form-select>

                    <x-form-select wire:model="editModel" label="Model">
                        <option value="">Default</option>
                        @if($editProvider && isset($providers[$editProvider]))
                            @foreach($providers[$editProvider]['models'] ?? [] as $modelKey => $modelInfo)
                                <option value="{{ $modelKey }}">{{ $modelInfo['label'] }}</option>
                            @endforeach
                        @endif
                    </x-form-select>

                    <div class="grid grid-cols-2 gap-2">
                        <x-form-input wire:model.number="editMaxTokens" label="Max Tokens" type="number" min="1" max="32768" />
                        <x-form-input wire:model.number="editTemperature" label="Temperature" type="number" min="0" max="2" step="0.1" />
                    </div>
                </div>

                {{-- System Prompt --}}
                <x-form-textarea wire:model="editSystemPrompt" label="System Prompt" rows="4" mono
                    :error="$errors->first('editSystemPrompt')" />

                {{-- Prompt Template --}}
                <x-form-textarea wire:model="editPromptTemplate" label="Prompt Template" rows="3" mono
                    hint="Use @{{input}} placeholders for schema fields" />

                {{-- Actions --}}
                <div class="flex items-center justify-between border-t border-gray-200 pt-4">
                    <button wire:click="deleteSkill" wire:confirm="Are you sure you want to delete this skill? This cannot be undone."
                        class="rounded-lg border border-red-300 px-4 py-2 text-sm font-medium text-red-700 hover:bg-red-50">
                        Delete Skill
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
                    <h2 class="text-xl font-semibold text-gray-900">{{ $skill->name }}</h2>
                    <x-status-badge :status="$skill->status->value" />
                    <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                        {{ match($skill->type->value) {
                            'llm' => 'bg-purple-100 text-purple-800',
                            'connector' => 'bg-blue-100 text-blue-800',
                            'rule' => 'bg-yellow-100 text-yellow-800',
                            'hybrid' => 'bg-green-100 text-green-800',
                            default => 'bg-gray-100 text-gray-800',
                        } }}">
                        {{ $skill->type->label() }}
                    </span>
                </div>
                @if($skill->description)
                    <p class="mt-1 text-sm text-gray-500">{{ $skill->description }}</p>
                @endif
            </div>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">v{{ $skill->current_version }}</span>
                <button wire:click="startEdit" class="rounded p-1.5 text-gray-400 hover:bg-gray-100 hover:text-gray-600" title="Edit Skill">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z"/></svg>
                </button>
                <button wire:click="toggleStatus"
                    class="rounded-lg border px-3 py-1.5 text-sm font-medium {{ $skill->status === \App\Domain\Skill\Enums\SkillStatus::Active ? 'border-red-300 text-red-700 hover:bg-red-50' : 'border-green-300 text-green-700 hover:bg-green-50' }}">
                    {{ $skill->status === \App\Domain\Skill\Enums\SkillStatus::Active ? 'Disable' : 'Enable' }}
                </button>
            </div>
        </div>

        {{-- Stats --}}
        <div class="mb-6 grid grid-cols-4 gap-4">
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ number_format($skill->execution_count) }}</div>
                <div class="text-sm text-gray-500">Total Executions</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ $skill->successRate() }}%</div>
                <div class="text-sm text-gray-500">Success Rate</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ number_format($skill->avg_latency_ms) }}ms</div>
                <div class="text-sm text-gray-500">Avg Latency</div>
            </div>
            <div class="rounded-xl border border-gray-200 bg-white p-4">
                <div class="text-2xl font-bold text-gray-900">{{ ucfirst($skill->risk_level->value) }}</div>
                <div class="text-sm text-gray-500">Risk Level</div>
            </div>
        </div>

        {{-- Tabs --}}
        <div class="mb-4 border-b border-gray-200">
            <nav class="-mb-px flex space-x-8">
                @foreach(['overview' => 'Overview', 'versions' => 'Versions', 'executions' => 'Executions'] as $tab => $label)
                    <button wire:click="$set('activeTab', '{{ $tab }}')"
                        class="whitespace-nowrap border-b-2 py-3 text-sm font-medium {{ $activeTab === $tab ? 'border-primary-500 text-primary-600' : 'border-transparent text-gray-500 hover:border-gray-300 hover:text-gray-700' }}">
                        {{ $label }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Tab Content --}}
        @if($activeTab === 'overview')
            <div class="grid grid-cols-2 gap-6">
                {{-- Input Schema --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Input Schema</h3>
                    @if(!empty($skill->input_schema['properties'] ?? []))
                        <div class="space-y-2">
                            @foreach($skill->input_schema['properties'] as $name => $def)
                                <div class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                                    <span class="font-mono text-sm">{{ $name }}</span>
                                    <span class="text-xs text-gray-500">{{ $def['type'] ?? 'any' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No schema defined</p>
                    @endif
                </div>

                {{-- Output Schema --}}
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Output Schema</h3>
                    @if(!empty($skill->output_schema['properties'] ?? []))
                        <div class="space-y-2">
                            @foreach($skill->output_schema['properties'] as $name => $def)
                                <div class="flex items-center justify-between rounded border border-gray-100 px-3 py-2">
                                    <span class="font-mono text-sm">{{ $name }}</span>
                                    <span class="text-xs text-gray-500">{{ $def['type'] ?? 'any' }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="text-sm text-gray-400">No schema defined</p>
                    @endif
                </div>

                {{-- Configuration --}}
                <div class="col-span-2 rounded-xl border border-gray-200 bg-white p-4">
                    <h3 class="mb-3 text-sm font-semibold text-gray-700">Configuration</h3>
                    <pre class="max-h-48 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($skill->configuration, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                </div>

                @if($skill->system_prompt)
                    <div class="col-span-2 rounded-xl border border-gray-200 bg-white p-4">
                        <h3 class="mb-3 text-sm font-semibold text-gray-700">System Prompt</h3>
                        <pre class="max-h-48 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700 whitespace-pre-wrap">{{ $skill->system_prompt }}</pre>
                    </div>
                @endif
            </div>
        @elseif($activeTab === 'versions')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Version</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Changelog</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Created</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($versions as $version)
                            <tr>
                                <td class="px-6 py-4 font-mono text-sm">{{ $version->version }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $version->changelog ?? '-' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $version->created_at->diffForHumans() }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="3" class="px-6 py-8 text-center text-sm text-gray-400">No versions found</td>
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
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Cost</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Error</th>
                            <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @forelse($executions as $exec)
                            <tr>
                                <td class="px-6 py-4">
                                    <x-status-badge :status="$exec->status" />
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $exec->duration_ms ? number_format($exec->duration_ms) . 'ms' : '-' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ $exec->cost_credits }} credits</td>
                                <td class="px-6 py-4 text-sm text-red-500 max-w-xs truncate">{{ $exec->error_message ?? '-' }}</td>
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
