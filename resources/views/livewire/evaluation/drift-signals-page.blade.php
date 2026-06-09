<div>
    {{-- Filters --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false">
        <x-form-select wire:model.live="typeFilter">
            <option value="">All Signal Types</option>
            @foreach($signalTypes as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="breachFilter">
            <option value="">All Statuses</option>
            <option value="breached">Breached</option>
            <option value="ok">Within baseline</option>
        </x-form-select>
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Signal</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Value</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Baseline</th>
                    <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Window</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Detected</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($signals as $signal)
                    <tr class="hover:bg-gray-50">
                        <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ $signal->signal_type->label() }}</td>
                        <td class="px-6 py-4 text-sm">
                            @if($signal->breached)
                                <span class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">Breached</span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-green-100 px-2.5 py-0.5 text-xs font-medium text-green-800">OK</span>
                            @endif
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-700">{{ $signal->value !== null ? number_format($signal->value, 4) : '—' }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $signal->baseline !== null ? number_format($signal->baseline, 4) : '—' }}</td>
                        <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">{{ $signal->window ?? '—' }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500" title="{{ $signal->detected_at }}">{{ $signal->detected_at->diffForHumans() }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-sm text-gray-400">No drift signals recorded yet.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $signals->links() }}
    </div>
</div>
