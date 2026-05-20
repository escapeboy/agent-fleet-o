<div>
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-semibold text-gray-900">Bug Reports</h1>
    </div>

    {{-- Filters row 1: existing dropdowns + reporter --}}
    <div class="flex flex-wrap gap-3 mb-3">
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

    {{-- Filters row 2: keyword search + date range --}}
    <div class="flex flex-wrap gap-3 mb-6">
        <div class="flex-1 min-w-48">
            <x-form-input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Search title, description, URL…"
            />
        </div>
        <div class="flex items-center gap-2">
            <x-form-input
                wire:model.live="dateFrom"
                type="date"
                placeholder="From"
            />
            <span class="text-gray-400 text-sm">–</span>
            <x-form-input
                wire:model.live="dateTo"
                type="date"
                placeholder="To"
            />
        </div>
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
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <button wire:click="sort('project_key')" class="flex items-center gap-1 hover:text-gray-900">
                            Project
                            @if($sortBy === 'project_key')
                                <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Title</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <button wire:click="sort('severity')" class="flex items-center gap-1 hover:text-gray-900">
                            Severity
                            @if($sortBy === 'severity')
                                <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <button wire:click="sort('status')" class="flex items-center gap-1 hover:text-gray-900">
                            Status
                            @if($sortBy === 'status')
                                <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <button wire:click="sort('reporter')" class="flex items-center gap-1 hover:text-gray-900">
                            Reporter
                            @if($sortBy === 'reporter')
                                <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                        <button wire:click="sort('suggested_type')" class="flex items-center gap-1 hover:text-gray-900">
                            Type
                            @if($sortBy === 'suggested_type')
                                <span>{{ $sortDir === 'asc' ? '↑' : '↓' }}</span>
                            @else
                                <span class="text-gray-300">↕</span>
                            @endif
                        </button>
                    </th>
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
                        $suggestedTypeLabels = [
                            'bug' => ['label' => 'Bug', 'class' => 'bg-red-100 text-red-800'],
                            'feature_request' => ['label' => 'Feature', 'class' => 'bg-blue-100 text-blue-800'],
                        ];
                        $isReopenable = in_array($report->status?->value, ['resolved', 'dismissed'], true);
                    @endphp
                    <tr
                        wire:navigate
                        class="hover:bg-gray-50 cursor-pointer {{ $severity === 'critical' ? 'border-l-4 border-l-red-500' : '' }}"
                        onclick="window.location='{{ route('bug-reports.show', $report) }}'"
                    >
                        <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                            <span title="{{ $report->created_at?->diffForHumans() }}">
                                {{ $report->created_at?->format('Y-m-d H:i') }}
                            </span>
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
                        <td class="px-4 py-3">
                            @if($report->suggested_type && isset($suggestedTypeLabels[$report->suggested_type]))
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium {{ $suggestedTypeLabels[$report->suggested_type]['class'] }}">
                                    {{ $suggestedTypeLabels[$report->suggested_type]['label'] }}
                                </span>
                            @else
                                <span class="text-gray-300">—</span>
                            @endif
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap" onclick="event.stopPropagation()">
                            @if($isReopenable)
                                <button
                                    wire:click.stop="reopen('{{ $report->id }}')"
                                    wire:confirm="Reopen this bug report?"
                                    class="text-gray-400 hover:text-blue-600 transition-colors mr-2"
                                    title="Reopen"
                                >
                                    <svg xmlns="http://www.w3.org/2000/svg" class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                    </svg>
                                </button>
                            @endif
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
                        <td colspan="8" class="px-4 py-8 text-center text-sm text-gray-500">
                            No bug reports found.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
        <p class="text-sm text-gray-600">
            @if ($reports->total() > 0)
                Showing <span class="font-medium">{{ $reports->firstItem() }}</span>–<span class="font-medium">{{ $reports->lastItem() }}</span>
                of <span class="font-medium">{{ $reports->total() }}</span>
                {{ Str::plural('bug report', $reports->total()) }}
            @else
                No bug reports yet
            @endif
        </p>
        <div>{{ $reports->links() }}</div>
    </div>
</div>
