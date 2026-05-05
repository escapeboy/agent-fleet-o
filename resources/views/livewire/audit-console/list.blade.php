    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 py-8">
        <div class="mb-6 flex items-center justify-between">
            <h1 class="text-xl font-semibold text-gray-900">Audit Console</h1>
            <a href="{{ route('audit-console.settings') }}" class="text-sm text-gray-600 hover:text-gray-900">Settings</a>
        </div>

        @if(session('success'))
            <div class="mb-4 rounded-md bg-green-50 border border-green-300 px-4 py-3 text-sm text-green-700">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div class="mb-4 rounded-md bg-red-50 border border-red-300 px-4 py-3 text-sm text-red-700">
                {{ session('error') }}
            </div>
        @endif

        {{-- Filters --}}
        <div class="mb-4 flex flex-wrap gap-3">
            <select wire:model.live="workflow" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500">
                <option value="">All Workflows</option>
                @foreach($workflows as $wf)
                    <option value="{{ $wf }}">{{ $wf }}</option>
                @endforeach
            </select>

            <select wire:model.live="status" class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="running">Running</option>
                <option value="completed">Completed</option>
                <option value="failed">Failed</option>
                <option value="tampered">Tampered</option>
            </select>

            <input wire:model.live.debounce.400ms="dateFrom" type="date" placeholder="From"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500">

            <input wire:model.live.debounce.400ms="dateTo" type="date" placeholder="To"
                   class="rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 focus:border-primary-500 focus:ring-primary-500">
        </div>

        {{-- Table --}}
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200 text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Workflow</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Subject</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Status</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Verification</th>
                        <th class="px-4 py-3 text-left font-medium text-gray-600">Created</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($decisions as $decision)
                        @php
                            $latestVerification = $decision->verifications->sortByDesc('checked_at')->first();
                        @endphp
                        <tr data-workflow="{{ $decision->workflow_name }}" class="hover:bg-gray-50 transition-colors">
                            <td class="px-4 py-3 text-gray-900">
                                <a href="{{ route('audit-console.show', $decision) }}" class="hover:text-primary-600">
                                    {{ $decision->workflow_name }}
                                    <span class="ml-1 text-xs text-gray-500">{{ $decision->workflow_version }}</span>
                                </a>
                            </td>
                            <td class="px-4 py-3 text-gray-600 text-xs font-mono">
                                {{ $decision->subject_type ? class_basename($decision->subject_type) : '—' }}
                                @if($decision->subject_id)
                                    #{{ substr($decision->subject_id, 0, 8) }}
                                @endif
                            </td>
                            <td class="px-4 py-3">
                                @php
                                    $statusColors = [
                                        'pending' => 'text-yellow-700 bg-yellow-100',
                                        'running' => 'text-blue-700 bg-blue-100',
                                        'completed' => 'text-green-700 bg-green-100',
                                        'failed' => 'text-red-700 bg-red-100',
                                        'tampered' => 'text-orange-700 bg-orange-100',
                                    ];
                                    $statusClass = $statusColors[$decision->status->value] ?? 'text-gray-600';
                                @endphp
                                <span class="rounded-full px-2 py-0.5 text-xs font-medium {{ $statusClass }}">
                                    {{ $decision->status->value }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                @if($latestVerification)
                                    <span class="text-xs {{ $latestVerification->status->value === 'passed' ? 'text-green-600' : 'text-red-600' }}">
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
