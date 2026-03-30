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
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <x-form-input wire:model="editName" label="Name" type="text"
                        :error="$errors->first('editName')" />
                    <x-form-select wire:model="editType" label="Type">
                        @foreach(\App\Domain\Skill\Enums\SkillType::cases() as $t)
                            @if($t->value !== 'browser' || config('browser.enabled', false))
                                <option value="{{ $t->value }}">{{ $t->label() }}</option>
                            @endif
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
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
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
        <div class="mb-6 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
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
            <div class="flex flex-wrap items-center gap-2">
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
            <nav class="-mb-px flex space-x-8 overflow-x-auto scrollbar-none">
                @foreach(['overview' => 'Overview', 'versions' => 'Versions', 'executions' => 'Executions', 'playground' => 'Playground', 'benchmark' => 'Benchmark'] as $tab => $label)
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

                    {{-- Resolved Provider indicator --}}
                    <div class="mb-3 flex items-center gap-2">
                        <span class="text-xs font-medium text-gray-500">LLM:</span>
                        <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                            {{ $resolvedProvider['provider'] }}/{{ $resolvedProvider['model'] }}
                        </span>
                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ match($resolvedProvider['source']) {
                            'skill' => 'bg-green-50 text-green-700',
                            'skill_split' => 'bg-green-50 text-green-700',
                            'agent' => 'bg-blue-50 text-blue-700',
                            'team' => 'bg-purple-50 text-purple-700',
                            'platform' => 'bg-amber-50 text-amber-700',
                            'config' => 'bg-gray-50 text-gray-500',
                            default => 'bg-gray-50 text-gray-500',
                        } }}">
                            {{ match($resolvedProvider['source']) {
                                'skill' => 'skill override',
                                'skill_split' => 'skill (split mode)',
                                'agent' => 'from agent',
                                'team' => 'team default',
                                'platform' => 'platform',
                                'config' => 'system default',
                                default => $resolvedProvider['source'],
                            } }}
                        </span>
                    </div>

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
                <div class="overflow-x-auto">
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
            </div>

            {{-- Version lineage DAG — collapsible panel showing parent→child evolution graph --}}
            <livewire:skills.skill-lineage-panel :skill-id="$skill->id" />
        @elseif($activeTab === 'executions')
            <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
                <div class="overflow-x-auto">
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
            </div>
        @elseif($activeTab === 'playground')
            @php
                $latestVersion = $versions->first();
            @endphp
            @if($latestVersion)
                <livewire:skills.skill-playground
                    :skill-id="$skill->id"
                    :version-id="$latestVersion->id"
                    :key="'playground-'.$skill->id"
                />
            @else
                <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-12 text-center">
                    <p class="text-sm text-gray-500">No versions found. Save the skill configuration first to create a version.</p>
                </div>
            @endif

        @elseif($activeTab === 'benchmark')
            {{-- ====== BENCHMARK TAB ====== --}}
            <div class="space-y-6">

                {{-- Start benchmark form --}}
                @if(!$benchmarkRunning)
                <div class="rounded-xl border border-gray-200 bg-white p-6">
                    <h3 class="mb-4 text-sm font-semibold text-gray-700">Start Improvement Loop</h3>
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                            <x-form-input wire:model="benchMetricName" label="Metric Name"
                                hint='e.g. latency_ms, output_length, json:score, regex:/(\d+\.\d+)/'
                                :error="$errors->first('benchMetricName')" />
                            <x-form-select wire:model="benchMetricDirection" label="Direction">
                                <option value="maximize">Maximize (higher is better)</option>
                                <option value="minimize">Minimize (lower is better)</option>
                            </x-form-select>
                        </div>
                        <x-form-textarea wire:model="benchTestInputs" label="Test Inputs (JSON array)"
                            rows="3" mono hint='e.g. [{"text": "hello world"}]'
                            :error="$errors->first('benchTestInputs')" />
                        <div class="grid grid-cols-2 gap-4 sm:grid-cols-4">
                            <x-form-input wire:model.number="benchTimeBudget" label="Time Budget (s)" type="number" min="60" />
                            <x-form-input wire:model.number="benchMaxIterations" label="Max Iterations" type="number" min="1" max="500" />
                            <x-form-input wire:model.number="benchComplexityPenalty" label="Complexity Penalty" type="number" step="0.001" min="0" />
                            <x-form-input wire:model.number="benchImprovementThreshold" label="Min Improvement" type="number" step="0.001" />
                        </div>
                        <div class="flex justify-end border-t border-gray-100 pt-4">
                            <button wire:click="startBenchmark" wire:loading.attr="disabled"
                                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                                <span wire:loading.remove wire:target="startBenchmark">Start Loop</span>
                                <span wire:loading wire:target="startBenchmark">Starting…</span>
                            </button>
                        </div>
                        @if(session()->has('benchmark_error'))
                            <div class="rounded-lg bg-red-50 p-3 text-sm text-red-700">{{ session('benchmark_error') }}</div>
                        @endif
                    </div>
                </div>
                @endif

                {{-- Active benchmark status --}}
                @if($activeBenchmark)
                <div class="rounded-xl border border-primary-200 bg-primary-50 p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-sm font-semibold text-primary-800">
                                    {{ $activeBenchmark->metric_name }} ({{ $activeBenchmark->metric_direction }})
                                </span>
                                <x-status-badge :status="$activeBenchmark->status->value" />
                            </div>
                            <div class="mt-1 text-xs text-primary-600">
                                Iteration {{ $activeBenchmark->iteration_count }} / {{ $activeBenchmark->max_iterations }}
                                &middot; {{ $activeBenchmark->elapsedSeconds() }}s / {{ $activeBenchmark->time_budget_seconds }}s
                            </div>
                        </div>
                        <div class="text-right">
                            <div class="text-xl font-bold text-primary-900">
                                @if($activeBenchmark->best_value !== null)
                                    {{ number_format($activeBenchmark->best_value, 4) }}
                                    @if($activeBenchmark->improvementPercent() != 0)
                                        <span class="text-sm font-normal {{ $activeBenchmark->improvementPercent() > 0 ? 'text-green-600' : 'text-red-600' }}">
                                            ({{ $activeBenchmark->improvementPercent() > 0 ? '+' : '' }}{{ $activeBenchmark->improvementPercent() }}%)
                                        </span>
                                    @endif
                                @else
                                    —
                                @endif
                            </div>
                            <div class="text-xs text-primary-600">Best value (baseline: {{ number_format((float)$activeBenchmark->baseline_value, 4) }})</div>
                        </div>
                    </div>
                    @if($activeBenchmark->isRunning())
                    <div class="mt-3 flex justify-end">
                        <button wire:click="cancelBenchmark" wire:loading.attr="disabled"
                            class="rounded-lg border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                            Cancel Loop
                        </button>
                    </div>
                    @endif
                </div>
                @endif

                {{-- Benchmark history --}}
                @if($benchmarks->isNotEmpty())
                <div class="rounded-xl border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-5 py-3">
                        <h3 class="text-sm font-semibold text-gray-700">Benchmark History</h3>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50 text-left text-xs font-medium text-gray-500">
                                <th class="px-4 py-3">Metric</th>
                                <th class="px-4 py-3">Status</th>
                                <th class="px-4 py-3">Iterations</th>
                                <th class="px-4 py-3">Baseline</th>
                                <th class="px-4 py-3">Best</th>
                                <th class="px-4 py-3">Improvement</th>
                                <th class="px-4 py-3">Started</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($benchmarks as $b)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $b->metric_name }}</td>
                                <td class="px-4 py-3"><x-status-badge :status="$b->status->value" /></td>
                                <td class="px-4 py-3 text-gray-600">{{ $b->iteration_count }} / {{ $b->max_iterations }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $b->baseline_value !== null ? number_format($b->baseline_value, 4) : '—' }}</td>
                                <td class="px-4 py-3 text-gray-600">{{ $b->best_value !== null ? number_format($b->best_value, 4) : '—' }}</td>
                                <td class="px-4 py-3">
                                    @if($b->improvementPercent() != 0)
                                        <span class="{{ $b->improvementPercent() > 0 ? 'text-green-600' : 'text-red-600' }} font-medium">
                                            {{ $b->improvementPercent() > 0 ? '+' : '' }}{{ $b->improvementPercent() }}%
                                        </span>
                                    @else
                                        <span class="text-gray-400">0%</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-400 text-xs">{{ $b->started_at?->diffForHumans() ?? '—' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>

                {{-- Iteration log for active/selected benchmark --}}
                @if($activeBenchmark && $activeBenchmark->iterationLogs->isNotEmpty())
                <div class="rounded-xl border border-gray-200 bg-white">
                    <div class="border-b border-gray-200 px-5 py-3">
                        <h3 class="text-sm font-semibold text-gray-700">Iteration Log (current benchmark)</h3>
                    </div>
                    <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100 bg-gray-50 text-left text-xs font-medium text-gray-500">
                                <th class="px-4 py-3">#</th>
                                <th class="px-4 py-3">Outcome</th>
                                <th class="px-4 py-3">Metric</th>
                                <th class="px-4 py-3">Eff. Improvement</th>
                                <th class="px-4 py-3">Complexity Δ</th>
                                <th class="px-4 py-3">Duration</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            @foreach($activeBenchmark->iterationLogs->sortByDesc('iteration_number')->take(20) as $log)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3 text-gray-500">{{ $log->iteration_number }}</td>
                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ match($log->outcome->value) {
                                            'keep' => 'bg-green-100 text-green-700',
                                            'discard' => 'bg-yellow-100 text-yellow-700',
                                            'crash', 'timeout' => 'bg-red-100 text-red-700',
                                            default => 'bg-gray-100 text-gray-700',
                                        } }}">
                                        {{ $log->outcome->label() }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 font-mono text-xs text-gray-700">{{ $log->metric_value !== null ? number_format($log->metric_value, 4) : '—' }}</td>
                                <td class="px-4 py-3 font-mono text-xs {{ ($log->effective_improvement ?? 0) > 0 ? 'text-green-600' : 'text-gray-500' }}">
                                    {{ $log->effective_improvement !== null ? number_format($log->effective_improvement, 4) : '—' }}
                                </td>
                                <td class="px-4 py-3 font-mono text-xs {{ ($log->complexity_delta ?? 0) > 0 ? 'text-orange-500' : 'text-gray-500' }}">
                                    {{ $log->complexity_delta !== null ? (($log->complexity_delta > 0 ? '+' : '').$log->complexity_delta) : '—' }}
                                </td>
                                <td class="px-4 py-3 text-gray-400 text-xs">{{ $log->duration_ms !== null ? $log->duration_ms.'ms' : '—' }}</td>
                            </tr>
                            @endforeach
                        </tbody>
                    </table>
                    </div>
                </div>
                @endif

                @else
                    <div class="rounded-xl border border-dashed border-gray-300 bg-gray-50 p-12 text-center">
                        <p class="text-sm text-gray-400">No benchmarks yet. Start a loop above to begin optimising this skill.</p>
                    </div>
                @endif
            </div>
        @endif
    @endif

    {{-- Plugin extension point: inject custom content into skill detail --}}
    @stack('fleet.skill.detail')
</div>
