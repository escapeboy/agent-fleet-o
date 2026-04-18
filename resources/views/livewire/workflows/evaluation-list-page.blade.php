<div>
    {{-- Page header --}}
    <div class="flex items-center justify-between mb-6">
        <div>
            <h1 class="text-xl font-semibold text-gray-900 dark:text-white">Flow Evaluations</h1>
            <p class="text-sm text-gray-500 mt-0.5">Test workflows against datasets and score outputs with an LLM judge.</p>
        </div>
        <button wire:click="$set('showCreateForm', true)"
                class="inline-flex items-center gap-1.5 rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700 transition-colors">
            <i class="fas fa-plus h-4 w-4"></i>
            New Dataset
        </button>
    </div>

    {{-- Alerts --}}
    @if($success)
        <div class="mb-4 rounded-lg bg-green-50 border border-green-200 px-4 py-3 text-sm text-green-800 dark:bg-green-900/20 dark:border-green-700 dark:text-green-300">
            {{ $success }}
        </div>
    @endif
    @if($error)
        <div class="mb-4 rounded-lg bg-red-50 border border-red-200 px-4 py-3 text-sm text-red-800 dark:bg-red-900/20 dark:border-red-700 dark:text-red-300">
            {{ $error }}
        </div>
    @endif

    {{-- Create dataset form --}}
    @if($showCreateForm)
        <div class="mb-6 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 p-5 shadow-sm">
            <h2 class="text-sm font-semibold text-gray-900 dark:text-white mb-4">Create Dataset</h2>
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <x-form-input wire:model="datasetName" label="Name" placeholder="My evaluation dataset" required />
                <x-form-select wire:model="datasetWorkflowId" label="Workflow (optional)">
                    <option value="">— none —</option>
                    @foreach($workflows as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </x-form-select>
                <div class="sm:col-span-2">
                    <x-form-textarea wire:model="datasetDescription" label="Description" rows="2" />
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <button wire:click="createDataset"
                        class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700 transition-colors">
                    Create
                </button>
                <button wire:click="$set('showCreateForm', false)"
                        class="rounded-lg border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors">
                    Cancel
                </button>
            </div>
        </div>
    @endif

    {{-- Datasets table --}}
    <div class="rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-sm overflow-hidden">
        @if($datasets->isEmpty())
            <div class="py-16 text-center text-sm text-gray-500">
                No evaluation datasets yet. Create one to start testing your workflows.
            </div>
        @else
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-700/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Workflow</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Rows</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Latest Score</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Runs</th>
                        <th class="relative px-4 py-3"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                    @foreach($datasets as $dataset)
                        @php
                            $latestRun = $runsByDataset->get($dataset->id)?->first();
                            $meanScore = $latestRun?->summary['mean_score'] ?? null;
                            $passRate = $latestRun?->summary['pass_rate'] ?? null;
                        @endphp
                        <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors">
                            <td class="px-4 py-3">
                                <div class="text-sm font-medium text-gray-900 dark:text-white">{{ $dataset->name }}</div>
                                @if($dataset->description)
                                    <div class="text-xs text-gray-500 truncate max-w-xs">{{ $dataset->description }}</div>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                {{ $dataset->workflow?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                {{ number_format($dataset->row_count) }}
                            </td>
                            <td class="px-4 py-3">
                                @if($meanScore !== null)
                                    <span class="inline-flex items-center gap-1 text-sm font-medium {{ $meanScore >= 0.7 ? 'text-green-600 dark:text-green-400' : 'text-amber-600 dark:text-amber-400' }}">
                                        {{ number_format($meanScore * 100, 1) }}%
                                    </span>
                                    @if($passRate !== null)
                                        <span class="ml-1 text-xs text-gray-400">({{ number_format($passRate * 100, 0) }}% pass)</span>
                                    @endif
                                @else
                                    <span class="text-sm text-gray-400">—</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400">
                                {{ $dataset->runs_count }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if($dataset->workflow_id)
                                        <button wire:click="startRun('{{ $dataset->id }}')"
                                                wire:confirm="Start a new evaluation run for this dataset?"
                                                class="rounded-md bg-primary-50 dark:bg-primary-900/20 px-2.5 py-1 text-xs font-medium text-primary-700 dark:text-primary-300 hover:bg-primary-100 dark:hover:bg-primary-900/40 transition-colors">
                                            Run
                                        </button>
                                    @endif
                                    <button wire:click="deleteDataset('{{ $dataset->id }}')"
                                            wire:confirm="Delete this dataset? All rows and runs will be removed."
                                            class="rounded-md px-2.5 py-1 text-xs font-medium text-red-600 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20 transition-colors">
                                        Delete
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            @if($datasets->hasPages())
                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                    {{ $datasets->links() }}
                </div>
            @endif
        @endif
    </div>
</div>
