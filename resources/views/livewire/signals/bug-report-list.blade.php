<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Bug Reports</h1>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-6">
        <select wire:model.live="projectFilter" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
            <option value="">All Projects</option>
            @foreach($projects as $project)
                <option value="{{ $project }}">{{ $project }}</option>
            @endforeach
        </select>

        <select wire:model.live="severityFilter" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
            <option value="">All Severities</option>
            @foreach($severities as $sev)
                <option value="{{ $sev }}">{{ ucfirst($sev) }}</option>
            @endforeach
        </select>

        <select wire:model.live="statusFilter" class="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </select>

        <input
            wire:model.live.debounce.300ms="reporterFilter"
            type="text"
            placeholder="Reporter..."
            class="rounded-md border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500"
        />
    </div>

    {{-- Table --}}
    <div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <button wire:click="sort('created_at')" class="flex items-center gap-1 hover:text-gray-900">
                            Date
                            @if($sortBy === 'created_at')
                                <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Project</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Severity</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Reporter</th>
                </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-100">
                @forelse($reports as $report)
                    @php
                        $severity = $report->payload['severity'] ?? 'minor';
                        $severityColors = [
                            'critical' => 'bg-red-100 text-red-800',
                            'major' => 'bg-orange-100 text-orange-800',
                            'minor' => 'bg-yellow-100 text-yellow-800',
                            'cosmetic' => 'bg-gray-100 text-gray-800',
                        ];
                        $statusColor = $report->status?->color() ?? 'gray';
                    @endphp
                    <tr
                        wire:navigate
                        class="hover:bg-gray-50 cursor-pointer {{ $severity === 'critical' ? 'border-l-4 border-l-red-500' : '' }}"
                        onclick="window.location='{{ route('bug-reports.show', $report) }}'"
                    >
                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                            {{ $report->created_at?->diffForHumans() }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">
                            {{ $report->project_key ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm font-medium text-gray-900 max-w-xs truncate">
                            {{ $report->payload['title'] ?? '—' }}
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $severityColors[$severity] ?? 'bg-gray-100 text-gray-800' }}">
                                {{ ucfirst($severity) }}
                            </span>
                        </td>
                        <td class="px-4 py-3">
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-{{ $statusColor }}-100 text-{{ $statusColor }}-800">
                                {{ $report->status?->label() ?? 'Received' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600">
                            {{ $report->payload['reporter_name'] ?? '—' }}
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-8 text-center text-sm text-gray-500">
                            No bug reports found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $reports->links() }}
    </div>
</div>
