<div>
    {{-- Flash messages --}}
    @if(session()->has('message'))
        <div class="mb-4 rounded-lg bg-green-50 p-3 text-sm text-green-700">{{ session('message') }}</div>
    @endif

    <p class="mb-6 text-sm text-gray-500">
        Named, clustered production failure modes from the Diagnose stage. Each mode accumulates occurrences over time and points at a remediation lever.
    </p>

    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search error modes...">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 text-base -translate-y-1/2 text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <div class="w-56">
            <x-form-select wire:model.live="leverFilter" compact>
                <option value="">All levers</option>
                @foreach($levers as $lever)
                    <option value="{{ $lever->value }}">{{ $lever->label() }}</option>
                @endforeach
            </x-form-select>
        </div>
    </div>

    {{-- Empty state --}}
    @if($errorModes->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-xl border border-dashed border-gray-300 bg-white py-16">
            <i class="fa-solid fa-bug-slash mb-4 text-5xl text-gray-400"></i>
            <p class="mb-1 text-sm font-medium text-gray-900">No error modes catalogued yet</p>
            <p class="text-sm text-gray-500">Error modes appear here as the Diagnose stage clusters production failures.</p>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Error Mode</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Occurrences</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Last Seen</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Lever</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @foreach($errorModes as $mode)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <p class="font-medium text-gray-900">{{ $mode->name }}</p>
                                @if($mode->description)
                                    <p class="mt-0.5 text-xs text-gray-400">{{ \Illuminate\Support\Str::limit($mode->description, 100) }}</p>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                    @class([
                                        'bg-red-100 text-red-800' => $mode->status === \App\Domain\ErrorMode\Enums\ErrorModeStatus::Open,
                                        'bg-yellow-100 text-yellow-800' => $mode->status === \App\Domain\ErrorMode\Enums\ErrorModeStatus::Mitigated,
                                        'bg-gray-100 text-gray-600' => $mode->status === \App\Domain\ErrorMode\Enums\ErrorModeStatus::Closed,
                                    ])">
                                    {{ ucfirst($mode->status->value) }}
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm font-medium text-gray-700">
                                {{ number_format($mode->occurrence_count) }}
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-500">
                                {{ $mode->last_seen_at?->diffForHumans() ?? '—' }}
                            </td>
                            <td class="px-6 py-4">
                                @can('edit-content')
                                    <select
                                        wire:change="assignLever('{{ $mode->id }}', $event.target.value)"
                                        class="rounded-lg border border-gray-300 py-1.5 text-sm focus:border-primary-500 focus:ring-primary-500">
                                        @foreach($levers as $lever)
                                            <option value="{{ $lever->value }}" @selected($mode->lever === $lever)>{{ $lever->label() }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-indigo-100 px-2.5 py-0.5 text-xs font-medium text-indigo-800">
                                        {{ $mode->lever->label() }}
                                    </span>
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            </div>
        </div>

        <div class="mt-4">
            {{ $errorModes->links() }}
        </div>
    @endif
</div>
