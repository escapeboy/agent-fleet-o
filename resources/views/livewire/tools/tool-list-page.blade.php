<div>
    {{-- Toolbar --}}
    <div class="mb-6 flex flex-wrap items-center gap-4">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search tools..." class="pl-10">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="typeFilter">
            <option value="">All Types</option>
            @foreach($types as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="statusFilter">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('tools.create') }}"
            class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
            New Tool
        </a>
    </div>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
                <tr>
                    @php
                        $sortIcon = fn($field) => $sortField === $field
                            ? ($sortDirection === 'asc' ? '&#9650;' : '&#9660;')
                            : '<span class="text-gray-300">&#9650;</span>';
                    @endphp
                    <th wire:click="sortBy('name')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Name {!! $sortIcon('name') !!}
                    </th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Functions</th>
                    <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Agents</th>
                    <th wire:click="sortBy('created_at')" class="cursor-pointer px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500 hover:text-gray-700">
                        Added {!! $sortIcon('created_at') !!}
                    </th>
                    <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Enabled</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-200">
                @forelse($tools as $tool)
                    @php
                        $isPlatform = $tool->isPlatformTool();
                        $active = $isPlatform
                            ? ($activations->get($tool->id)?->isActive() ?? false)
                            : $tool->status->value === 'active';
                    @endphp
                    <tr class="transition hover:bg-gray-50">
                        <td class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <a href="{{ route('tools.show', $tool) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                    {{ $tool->name }}
                                </a>
                                @if($isPlatform)
                                    <span class="inline-flex items-center rounded-full bg-violet-100 px-2 py-0.5 text-xs font-medium text-violet-700">Platform</span>
                                @endif
                            </div>
                            @if($tool->description)
                                <p class="mt-0.5 max-w-xs truncate text-xs text-gray-400">{{ $tool->description }}</p>
                            @endif
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium
                                {{ match($tool->type->value) {
                                    'mcp_stdio' => 'bg-blue-100 text-blue-800',
                                    'mcp_http' => 'bg-cyan-100 text-cyan-800',
                                    'built_in' => 'bg-amber-100 text-amber-800',
                                    default => 'bg-gray-100 text-gray-800',
                                } }}">
                                {{ $tool->type->label() }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->functionCount() }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->agents_count }}</td>
                        <td class="px-6 py-4 text-sm text-gray-500">{{ $tool->created_at->diffForHumans() }}</td>
                        <td class="px-6 py-4 text-right">
                            <button wire:click="toggleStatus('{{ $tool->id }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="toggleStatus('{{ $tool->id }}')"
                                    title="{{ $active ? 'Disable' : 'Enable' }} {{ $tool->name }}"
                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 focus-visible:ring-offset-2 disabled:opacity-50
                                        {{ $active ? 'bg-primary-600' : 'bg-gray-200' }}"
                                    role="switch"
                                    aria-checked="{{ $active ? 'true' : 'false' }}">
                                <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out
                                    {{ $active ? 'translate-x-5' : 'translate-x-0' }}"></span>
                            </button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center">
                            @if($search || $typeFilter || $statusFilter)
                                <p class="text-sm text-gray-400">No tools match your filters.</p>
                            @else
                                <svg class="mx-auto mb-3 h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17l-5.21-3.01a.88.88 0 010-1.52l10.32-5.96a.88.88 0 011.32.76v11.12a.88.88 0 01-1.32.76l-5.11-2.95" />
                                </svg>
                                <p class="text-sm font-medium text-gray-600">No integrations yet</p>
                                <p class="mt-1 text-xs text-gray-400">
                                    Pre-built integrations (GitHub, Slack, Notion, Linear…) are added during platform setup.
                                    You can also <a href="{{ route('tools.create') }}" class="text-primary-600 hover:underline">add a custom tool</a>.
                                </p>
                            @endif
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $tools->links() }}
    </div>
</div>
