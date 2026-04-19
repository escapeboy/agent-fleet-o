<div>
    <div class="mb-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Tool Search History</h1>
            <p class="mt-1 text-sm text-gray-500">
                Auto-discovery events from agents with <code class="rounded bg-gray-100 px-1">use_tool_search</code> enabled.
                Useful for tuning queries and verifying which tools are surfaced.
            </p>
        </div>
    </div>

    <div class="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="sm:col-span-2">
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Search queries…"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500" />
        </div>
        <div>
            <select wire:model.live="agentFilter"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                <option value="">All agents</option>
                @foreach($agents as $agent)
                    <option value="{{ $agent->id }}">{{ $agent->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    @if($logs->isEmpty())
        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 p-10 text-center">
            <p class="text-sm text-gray-600">
                No tool search events yet.
                @if($agents->isEmpty())
                    Enable <code class="rounded bg-gray-100 px-1">use_tool_search</code> on an agent's config first.
                @endif
            </p>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 bg-white shadow-sm">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">When</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Agent</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Query</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Pool</th>
                        <th class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wide text-gray-500">Matched</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Tools</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100 bg-white">
                    @foreach($logs as $log)
                        <tr class="hover:bg-gray-50">
                            <td class="whitespace-nowrap px-4 py-3 text-xs text-gray-500">{{ $log->created_at->diffForHumans() }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-sm text-gray-900">
                                @if($log->agent)
                                    <a href="{{ route('agents.show', $log->agent) }}" class="text-primary-600 hover:text-primary-800">{{ $log->agent->name }}</a>
                                @else
                                    <span class="text-gray-400">— deleted —</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-700">
                                <span class="font-mono text-xs">{{ \Illuminate\Support\Str::limit($log->query, 140) }}</span>
                            </td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm text-gray-600">{{ $log->pool_size }}</td>
                            <td class="whitespace-nowrap px-4 py-3 text-right text-sm font-medium text-gray-900">{{ $log->matched_count }}</td>
                            <td class="px-4 py-3">
                                @foreach($log->matched_slugs as $slug)
                                    <span class="mr-1 inline-block rounded bg-indigo-50 px-2 py-0.5 text-xs text-indigo-700">{{ $slug }}</span>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="mt-4">
            {{ $logs->links() }}
        </div>
    @endif
</div>
