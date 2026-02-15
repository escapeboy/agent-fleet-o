<div>
    {{-- Filter --}}
    <div class="mb-4">
        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach(\App\Domain\Project\Enums\ProjectRunStatus::cases() as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Run #</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Duration</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Cost</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Pipeline</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Started</th>
                </tr>
            </thead>
            @forelse($runs as $run)
                <tbody class="divide-y divide-gray-200" x-data="{ showOutput: false }">
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">#{{ $run->run_number }}</td>
                        <td class="px-6 py-4"><x-status-badge :status="$run->status->value" /></td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $run->durationForHumans() ?? '--' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $run->cost_credits ? $run->cost_credits . ' credits' : '--' }}</td>
                        <td class="px-6 py-4 text-sm">
                            <div class="flex items-center gap-2">
                                @if($run->experiment_id)
                                    <a href="{{ route('experiments.show', $run->experiment_id) }}" class="text-primary-600 hover:underline">Pipeline</a>
                                @endif
                                @if($run->output_summary || $run->artifacts_count > 0)
                                    <button @click="showOutput = !showOutput" class="text-primary-600 hover:underline" x-text="showOutput ? 'Hide output' : 'View output'"></button>
                                @else
                                    @if(!$run->experiment_id)
                                        <span class="text-gray-400">--</span>
                                    @endif
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $run->created_at->diffForHumans() }}</td>
                    </tr>
                    @if($run->output_summary || $run->artifacts_count > 0)
                        <tr x-show="showOutput" x-cloak>
                            <td colspan="6" class="bg-gray-50 px-6 py-4">
                                @if($run->output_summary)
                                    <div class="mb-3">
                                        <h4 class="mb-1 text-xs font-semibold uppercase tracking-wider text-gray-500">Output Summary</h4>
                                        <div class="max-h-96 overflow-y-auto rounded bg-white p-3 border border-gray-200 text-sm text-gray-700 prose-output">
                                            {!! \App\Domain\Experiment\Services\ArtifactContentResolver::renderAsHtml($run->output_summary, 3000) !!}
                                        </div>
                                    </div>
                                @endif
                                @if($run->artifacts_count > 0)
                                    <div>
                                        <livewire:experiments.artifact-list :artifact-owner="$run" wire:key="run-artifacts-{{ $run->id }}" />
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endif
                </tbody>
            @empty
                <tbody>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">
                            No runs found.
                        </td>
                    </tr>
                </tbody>
            @endforelse
        </table>
    </div>

    <div class="mt-4">
        {{ $runs->links() }}
    </div>
</div>
