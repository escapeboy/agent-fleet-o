<div>
    <div class="mb-6">
        <h2 class="text-lg font-semibold text-gray-900">Workflow Compensation</h2>
        <p class="mt-1 text-sm text-gray-500">
            Failed workflow runs whose completed steps had compensation (saga rollback) nodes defined.
        </p>
    </div>

    @if($runs->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
            <i class="fa-solid fa-rotate-left mb-3 text-3xl text-gray-300"></i>
            <p class="text-sm text-gray-500">No compensation activity.</p>
            <p class="mt-1 text-xs text-gray-400">
                Compensation triggers only on failed workflow runs that have completed steps with a compensation node.
            </p>
        </div>
    @else
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Experiment</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Workflow</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Compensated steps</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Failed at</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($runs as $run)
                        @php($experiment = $run['experiment'])
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 text-sm">
                                <a href="{{ route('experiments.show', $experiment) }}" wire:navigate
                                   class="font-medium text-primary-600 hover:text-primary-700">
                                    {{ $experiment->title }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                {{ $experiment->workflow?->name ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm">
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                                    {{ str($experiment->status->value)->replace('_', ' ')->title() }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                <span class="inline-flex items-center gap-1.5">
                                    <i class="fa-solid fa-rotate-left text-amber-500"></i>
                                    {{ $run['compensated_count'] }}
                                </span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-500">
                                {{ $experiment->updated_at?->diffForHumans() }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <p class="mt-3 text-xs text-gray-400">
            Compensation runs are reconstructed from experiment + step data — they are not stored as standalone records.
        </p>
    @endif
</div>
