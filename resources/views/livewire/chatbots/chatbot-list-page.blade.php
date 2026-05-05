<div>
    {{-- Flash API key banner --}}
    @if(session('chatbot_api_key'))
        <div class="mb-6 rounded-xl border border-green-200 bg-green-50 p-4">
            <div class="flex items-start gap-3">
                <i class="fa-solid fa-key mt-0.5 text-lg shrink-0 text-green-600"></i>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-semibold text-green-800">Your API key (shown once)</p>
                    <code class="mt-1 block break-all rounded bg-green-100 px-2 py-1 text-xs font-mono text-green-900">{{ session('chatbot_api_key') }}</code>
                    <p class="mt-1 text-xs text-green-700">Copy this now. It will not be shown again.</p>
                </div>
            </div>
        </div>
    @endif

    @if(session('message'))
        <div class="mb-4 rounded-lg bg-primary-50 px-4 py-3 text-sm text-primary-700">{{ session('message') }}</div>
    @endif

    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false" toolname="search_chatbots" tooldescription="Filter chatbot instances by status and search query">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search chatbots..." class="pl-10" toolparamdescription="Free-text search across chatbot names and descriptions">
                <x-slot:leadingIcon>
                    <i class="fa-solid fa-magnifying-glass pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-base text-gray-400"></i>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="statusFilter" toolparamdescription="Filter by chatbot status: active, disabled">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="typeFilter">
            <option value="">All Types</option>
            @foreach($types as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </x-form-select>

        @if($canCreate)
            <a href="{{ route('chatbots.create') }}"
               class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                New Chatbot
            </a>
        @else
            <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed" title="Plan limit reached">
                New Chatbot
            </span>
        @endif
    </form>

    {{-- Table --}}
    <div class="overflow-hidden rounded-xl border border-gray-200 bg-white">
        <div class="overflow-x-auto">
            <table class="w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Name</th>
                        <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Type</th>
                        <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Status</th>
                        <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Channels</th>
                        <th class="hidden md:table-cell px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Created</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    @forelse($chatbots as $chatbot)
                        <tr class="transition hover:bg-gray-50">
                            <td class="px-6 py-4">
                                <a href="{{ route('chatbots.show', $chatbot) }}" class="font-medium text-primary-600 hover:text-primary-800">
                                    {{ $chatbot->name }}
                                </a>
                                @if($chatbot->description)
                                    <p class="mt-0.5 truncate text-xs text-gray-400">{{ $chatbot->description }}</p>
                                @endif
                                <p class="mt-0.5 text-xs text-gray-400 font-mono">{{ $chatbot->slug }}</p>
                            </td>
                            <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">
                                {{ $chatbot->type->label() }}
                            </td>
                            <td class="px-6 py-4">
                                @php
                                    $statusColors = [
                                        'active' => 'bg-green-100 text-green-700',
                                        'inactive' => 'bg-yellow-100 text-yellow-700',
                                        'draft' => 'bg-gray-100 text-gray-600',
                                        'suspended' => 'bg-red-100 text-red-700',
                                    ];
                                    $colorClass = $statusColors[$chatbot->status->value] ?? 'bg-gray-100 text-gray-600';
                                @endphp
                                <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium {{ $colorClass }}">
                                    {{ $chatbot->status->label() }}
                                </span>
                            </td>
                            <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">
                                {{ $chatbot->active_channels_count }}
                            </td>
                            <td class="hidden md:table-cell px-6 py-4 text-sm text-gray-500">
                                {{ $chatbot->created_at->diffForHumans() }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center text-sm text-gray-400">
                                No chatbots yet. Create your first one!
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-4">
        {{ $chatbots->links() }}
    </div>
</div>
