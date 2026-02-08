<div>
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
                <pre class="max-h-48 overflow-auto rounded-lg bg-gray-50 p-3 text-xs text-gray-700">{{ json_encode($skill->configuration, JSON_PRETTY_PRINT) }}</pre>
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
</div>
