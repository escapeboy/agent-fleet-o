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
            <tbody class="divide-y divide-gray-200">
                @forelse($runs as $run)
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">#{{ $run->run_number }}</td>
                        <td class="px-6 py-4"><x-status-badge :status="$run->status->value" /></td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $run->durationForHumans() ?? '--' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $run->cost_credits ? $run->cost_credits . ' credits' : '--' }}</td>
                        <td class="px-6 py-4 text-sm">
                            @if($run->experiment_id)
                                <a href="{{ route('experiments.show', $run->experiment_id) }}" class="text-primary-600 hover:underline">View details</a>
                            @else
                                <span class="text-gray-400">--</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $run->created_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">
                            No runs found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $runs->links() }}
    </div>
</div>
