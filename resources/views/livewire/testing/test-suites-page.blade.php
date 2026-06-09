<div>
    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search test suites..." class="pl-10">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="strategyFilter">
            <option value="">All Strategies</option>
            @foreach($strategies as $strategy)
                <option value="{{ $strategy->value }}">{{ $strategy->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="activeFilter">
            <option value="">All States</option>
            <option value="active">Active</option>
            <option value="inactive">Inactive</option>
        </x-form-select>
    </form>

    {{-- List --}}
    @if($suites->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center">
            @if($search || $strategyFilter || $activeFilter)
                <p class="text-sm text-gray-400">No test suites match your filters.</p>
            @else
                <i class="fa-solid fa-vial mx-auto mb-3 text-4xl text-gray-300"></i>
                <p class="text-sm font-medium text-gray-600">No test suites yet</p>
                <p class="mt-1 text-xs text-gray-400">
                    Test suites are created per project and run automatically against experiment output.
                </p>
            @endif
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
            <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Name</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Project</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Strategy</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Pass Rate</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Runs</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">Last Run</th>
                        <th class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-gray-500">State</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    @foreach($suites as $suite)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <a href="{{ route('testing.show', $suite) }}" class="font-medium text-primary-600 hover:text-primary-800">{{ $suite->name }}</a>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $suite->project?->name ?? '—' }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600">{{ $suite->test_strategy->label() }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">
                                {{ $suite->pass_rate !== null ? round($suite->pass_rate * 100).'%' : '—' }}
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600">{{ $suite->test_runs_count }}</td>
                            <td class="px-4 py-3 text-sm text-gray-400" title="{{ $suite->last_run_at?->toDateTimeString() }}">
                                {{ $suite->last_run_at?->diffForHumans() ?? 'never' }}
                            </td>
                            <td class="px-4 py-3">
                                @if($suite->is_active)
                                    <span class="inline-flex rounded-full bg-green-100 px-2 py-0.5 text-[11px] font-medium text-green-700">Active</span>
                                @else
                                    <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-500">Inactive</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="mt-4">
        {{ $suites->links() }}
    </div>
</div>
