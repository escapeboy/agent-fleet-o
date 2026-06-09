<div>
    @php
        $statusClasses = [
            'pending' => 'bg-gray-100 text-gray-700',
            'active' => 'bg-green-100 text-green-700',
            'sleeping' => 'bg-blue-100 text-blue-700',
            'completed' => 'bg-gray-100 text-gray-700',
            'cancelled' => 'bg-amber-100 text-amber-700',
            'failed' => 'bg-red-100 text-red-700',
        ];
    @endphp

    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false">
        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="agentFilter">
            <option value="">All Agents</option>
            @foreach($agents as $agent)
                <option value="{{ $agent->id }}">{{ $agent->name }}</option>
            @endforeach
        </x-form-select>
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-lg border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Agent</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Events</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Started</th>
                    <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Heartbeat</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @forelse($sessions as $session)
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <span class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium {{ $statusClasses[$session->status?->value] ?? 'bg-gray-100 text-gray-700' }}">
                                {{ $session->status?->label() ?? '—' }}
                            </span>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-900">
                            {{ $session->agent?->name ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-700">{{ $session->events_count }}</td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            {{ $session->started_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-500">
                            {{ $session->last_heartbeat_at?->diffForHumans() ?? '—' }}
                        </td>
                        <td class="px-4 py-3 text-right">
                            <a href="{{ route('agent-sessions.show', $session->id) }}"
                                class="text-sm font-medium text-primary-600 hover:text-primary-700">
                                View
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-4 py-10 text-center text-sm text-gray-500">
                            No agent sessions yet.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $sessions->links() }}
    </div>
</div>
