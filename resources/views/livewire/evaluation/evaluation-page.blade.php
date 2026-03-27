<div class="space-y-6">
    {{-- Stats --}}
    <div class="grid grid-cols-3 gap-4">
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Total Runs</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['runs_total']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Completed</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['runs_completed']) }}</p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-4">
            <p class="text-xs font-medium uppercase tracking-wider text-gray-500">Datasets</p>
            <p class="mt-1 text-2xl font-bold text-gray-900">{{ number_format($stats['datasets']) }}</p>
        </div>
    </div>

    {{-- Alerts --}}
    @if($success)
        <div class="rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800">{{ $success }}</div>
    @endif
    @if($error)
        <div class="rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800">{{ $error }}</div>
    @endif

    {{-- Tabs + Actions --}}
    <div class="flex items-center justify-between">
        <div class="flex gap-1 rounded-lg bg-gray-100 p-1">
            <button wire:click="setTab('runs')"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $activeTab === 'runs' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                Evaluation Runs
            </button>
            <button wire:click="setTab('datasets')"
                    class="rounded-md px-3 py-1.5 text-sm font-medium transition-colors {{ $activeTab === 'datasets' ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">
                Datasets
            </button>
        </div>
        <div class="flex gap-2">
            @if($activeTab === 'datasets')
                <button wire:click="$toggle('showDatasetForm')"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    New Dataset
                </button>
            @endif
            <button wire:click="$toggle('showEvalForm')"
                    class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                Quick Evaluate
            </button>
        </div>
    </div>

    {{-- Create Dataset Form --}}
    @if($showDatasetForm)
        <div class="rounded-xl border border-gray-200 bg-white p-5 space-y-4">
            <h3 class="text-sm font-semibold text-gray-900">Create Evaluation Dataset</h3>
            <x-form-input wire:model="datasetName" label="Name" type="text" placeholder="e.g. Customer Support Q&A" />
            <x-form-textarea wire:model="datasetDescription" label="Description" rows="2" placeholder="What does this dataset evaluate?" />
            <div class="flex justify-end gap-2">
                <button wire:click="$toggle('showDatasetForm')"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button wire:click="createDataset"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">Create</button>
            </div>
        </div>
    @endif

    {{-- Quick Evaluate Form --}}
    @if($showEvalForm)
        <div class="rounded-xl border border-gray-200 bg-white p-5 space-y-4">
            <h3 class="text-sm font-semibold text-gray-900">Quick Evaluate</h3>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Criteria</label>
                    <div class="flex flex-wrap gap-3">
                        @foreach($criteria as $criterion)
                            <label class="flex items-center gap-1.5 text-sm text-gray-700">
                                <input wire:model="evalCriteria" type="checkbox" value="{{ $criterion }}"
                                       class="rounded border-gray-300 text-primary-600" />
                                {{ ucfirst(str_replace('_', ' ', $criterion)) }}
                            </label>
                        @endforeach
                    </div>
                    @error('evalCriteria') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Agent (optional)</label>
                    <select wire:model="evalAgentId"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                        <option value="">No agent</option>
                        @foreach($agents as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Input / Question</label>
                    <textarea wire:model="evalInput" rows="3" placeholder="The input sent to the agent or LLM"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                    @error('evalInput') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 mb-1">Actual Output</label>
                    <textarea wire:model="evalActualOutput" rows="3" placeholder="The actual output produced"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                    @error('evalActualOutput') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Expected Output (optional)</label>
                    <textarea wire:model="evalExpectedOutput" rows="2" placeholder="The ideal / reference output"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-600 mb-1">Context (optional)</label>
                    <textarea wire:model="evalContext" rows="2" placeholder="Any reference context for faithfulness evaluation"
                              class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500"></textarea>
                </div>
            </div>

            {{-- Result --}}
            @if($evalResult)
                <div class="rounded-lg bg-gray-50 border border-gray-200 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-3">Scores</p>
                    <div class="flex flex-wrap gap-3">
                        @foreach($evalResult['scores'] as $criterion => $score)
                            <div class="flex flex-col items-center rounded-lg border border-gray-200 bg-white px-4 py-2 min-w-[80px]">
                                <span class="text-xs text-gray-500">{{ ucfirst(str_replace('_', ' ', $criterion)) }}</span>
                                <span class="text-xl font-bold {{ ($score ?? 0) >= 7 ? 'text-green-600' : (($score ?? 0) >= 4 ? 'text-amber-500' : 'text-red-500') }}">
                                    {{ $score !== null ? number_format($score, 1) : '—' }}<span class="text-xs font-normal text-gray-400">/10</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                    <p class="mt-2 text-xs text-gray-500">Cost: {{ $evalResult['total_cost_credits'] }} credits</p>
                </div>
            @endif

            <div class="flex justify-end gap-2">
                <button wire:click="$toggle('showEvalForm')"
                        class="rounded-lg border border-gray-300 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                <button wire:click="runEvaluation"
                        class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                    <span wire:loading.remove wire:target="runEvaluation">Run Evaluation</span>
                    <span wire:loading wire:target="runEvaluation">Running...</span>
                </button>
            </div>
        </div>
    @endif

    {{-- Evaluation Runs Tab --}}
    @if($activeTab === 'runs')
        <div class="rounded-xl border border-gray-200 bg-white overflow-hidden">
            @if($runs->isEmpty())
                <div class="py-12 text-center text-sm text-gray-500">
                    No evaluation runs yet. Use Quick Evaluate above or trigger evaluations from experiment detail pages.
                </div>
            @else
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Run</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Agent</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Criteria</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Scores</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Cost</th>
                            <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        @foreach($runs as $run)
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <span class="font-mono text-xs text-gray-400">{{ substr($run->id, 0, 8) }}</span>
                                    @if($run->dataset)
                                        <div class="text-xs text-gray-500">{{ $run->dataset->name }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-gray-600 text-xs">
                                    {{ $run->agent?->name ?? '—' }}
                                </td>
                                <td class="px-4 py-3">
                                    <div class="flex flex-wrap gap-1">
                                        @foreach($run->criteria ?? [] as $criterion)
                                            <span class="inline-flex rounded-full bg-gray-100 px-1.5 py-0.5 text-xs text-gray-600">{{ $criterion }}</span>
                                        @endforeach
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    @if($run->aggregate_scores)
                                        <div class="flex flex-wrap gap-1">
                                            @foreach($run->aggregate_scores as $criterion => $score)
                                                @if($score !== null)
                                                    <span class="inline-flex rounded-full px-1.5 py-0.5 text-xs font-medium
                                                        {{ $score >= 7 ? 'bg-green-100 text-green-700' : ($score >= 4 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700') }}">
                                                        {{ substr($criterion, 0, 3) }}: {{ number_format($score, 1) }}
                                                    </span>
                                                @endif
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-xs text-gray-400">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3">
                                    @php $status = $run->status->value ?? $run->status; @endphp
                                    <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ $status === 'completed' ? 'bg-green-100 text-green-700' : ($status === 'running' ? 'bg-blue-100 text-blue-700' : ($status === 'failed' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-500')) }}">
                                        {{ $status }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500">
                                    {{ $run->total_cost_credits ? $run->total_cost_credits.' cr' : '—' }}
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap">
                                    {{ $run->created_at->diffForHumans() }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="border-t border-gray-200 px-4 py-3">
                    {{ $runs->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- Datasets Tab --}}
    @if($activeTab === 'datasets')
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            @forelse($datasets as $dataset)
                <div class="rounded-xl border border-gray-200 bg-white p-4">
                    <div class="flex items-start justify-between">
                        <div>
                            <h3 class="font-medium text-gray-900">{{ $dataset->name }}</h3>
                            @if($dataset->description)
                                <p class="mt-0.5 text-sm text-gray-500">{{ $dataset->description }}</p>
                            @endif
                        </div>
                        <button wire:click="deleteDataset('{{ $dataset->id }}')"
                                wire:confirm="Delete this dataset?"
                                class="text-gray-400 hover:text-red-500">
                            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                        </button>
                    </div>
                    <div class="mt-3 flex gap-4 text-xs text-gray-500">
                        <span>{{ $dataset->case_count }} cases</span>
                        <span>{{ $dataset->runs_count }} runs</span>
                        <span>{{ $dataset->created_at->diffForHumans() }}</span>
                    </div>
                </div>
            @empty
                <div class="sm:col-span-2 lg:col-span-3 py-12 text-center text-sm text-gray-500">
                    No datasets yet. Create one above to organize your evaluation cases.
                </div>
            @endforelse
        </div>
    @endif
</div>
