<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Bug Reports</h1>
    </div>

    {{-- Filters --}}
    <div class="flex flex-wrap gap-3 mb-6">
        <x-form-select wire:model.live="projectFilter">
            <option value="">All Projects</option>
            @foreach($projects as $project)
                <option value="{{ $project }}">{{ $project }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="severityFilter">
            <option value="">All Severities</option>
            @foreach($severities as $sev)
                <option value="{{ $sev }}">{{ ucfirst($sev) }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-input
            wire:model.live.debounce.300ms="reporterFilter"
            type="text"
            placeholder="Reporter..."
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
                    <th class="px-4 py-3"></th>
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
                        <td class="px-4 py-3 text-right" onclick="event.stopPropagation()">
                            <button
                                wire:click.stop="delete('{{ $report->id }}')"
                                wire:confirm="Delete this bug report?"
                                class="text-gray-400 hover:text-red-600 transition-colors"
                                title="Delete"
                            >
                                <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
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
