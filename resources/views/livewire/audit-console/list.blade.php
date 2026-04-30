    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-xl font-semibold text-white">Audit Console</h1>
            <a href="{{ route('audit-console.settings') }}" class="text-sm text-gray-400 hover:text-white">Settings</a>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-md bg-green-900/40 border border-green-700 px-4 py-3 text-sm text-green-300">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 rounded-md bg-red-900/40 border border-red-700 px-4 py-3 text-sm text-red-300">
                {{ session('error') }}
            </div>
        @endif

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-3">
            <select wire:model.live="workflow" class="rounded-md border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-primary-500 focus:ring-primary-500">
                <option value="">All Workflows</option>
                @foreach($workflows as $wf)
                    <option value="{{ $wf }}">{{ $wf }}</option>
                @endforeach
            </select>

            <select wire:model.live="status" class="rounded-md border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-primary-500 focus:ring-primary-500">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="running">Running</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="tampered">Tampered</option>
            </select>

            <input wire:model.live.debounce.400ms="dateFrom" type="date" placeholder="From"
                   class="rounded-md border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-primary-500 focus:ring-primary-500">

            <input wire:model.live.debounce.400ms="dateTo" type="date" placeholder="To"
                   class="rounded-md border border-gray-700 bg-gray-800 px-3 py-2 text-sm text-gray-200 focus:border-primary-500 focus:ring-primary-500">
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-xl border border-gray-800 bg-gray-900">
            <table class="min-w-full divide-y divide-gray-800 text-sm">
                <thead class="bg-gray-800/50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-400">Workflow</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-400">Subject</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-400">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-400">Verification</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-400">Created</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-800">
                    @forelse($decisions as $decision)
                        @php
                            $latestVerification = $decision->verifications->sortByDesc('checked_at')->first();
                        @endphp
                        <tr data-workflow="{{ $decision->workflow_name }}" class="hover:bg-gray-800/30 transition-colors">
                            <td class="px-4 py-3 text-gray-200">
                                <a href="{{ route('audit-console.show', $decision) }}" class="hover:text-primary-400">
                                    {{ $decision->workflow_name }}
                                    <span class="ml-1 text-xs text-gray-500">{{ $decision->workflow_version }}</span>
                                </a>
                            </td>
                            <td class="px-4 py-3 text-gray-400 text-xs font-mono">
                                {{ $decision->subject_type ? class_basename($decision->subject_type) : '—' }}
                                @if($decision->subject_id)
                                    #{{ substr($decision->subject_id, 0, 8) }}
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $statusColors = [
                                        'pending' => 'text-yellow-400 bg-yellow-900/30',
                                        'running' => 'text-blue-400 bg-blue-900/30',
                                        'completed' => 'text-green-400 bg-green-900/30',
                                        'failed' => 'text-red-400 bg-red-900/30',
                                        'tampered' => 'text-orange-400 bg-orange-900/30',
                                    ];
                                    $statusClass = $statusColors[$decision->status->value] ?? 'text-gray-400';
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass }}">
                                    {{ $decision->status->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if($latestVerification)
                                    <span class="text-xs {{ $latestVerification->status->value === 'passed' ? 'text-green-400' : 'text-red-400' }}">
                                        {{ $latestVerification->status->value }}
                                    </span>
                                @else
                                    <span class="text-xs text-gray-500">unverified</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-gray-500 text-xs">
                                {{ $decision->created_at->diffForHumans() }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($decision->status->value === 'completed')
                                    <button wire:click="verify('{{ $decision->id }}')"
                                            class="text-xs text-primary-400 hover:text-primary-300 transition-colors">
                                        Verify
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-gray-500">
                                No audit decisions found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Pagination --}}
        <div class="mt-4">
            {{ $decisions->links() }}
        </div>
    </div>
