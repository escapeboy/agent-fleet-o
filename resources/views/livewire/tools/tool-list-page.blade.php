<div>
    {{-- Toolbar --}}
    <form class="mb-6 flex flex-wrap items-center gap-4" onsubmit="return false" toolname="search_tools" tooldescription="Filter tools by type, status, and search query">
        <div class="relative flex-1">
            <x-form-input wire:model.live.debounce.300ms="search" type="text" placeholder="Search tools..." class="pl-10" toolparamdescription="Free-text search across tool names and descriptions">
                <x-slot:leadingIcon>
                    <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                </x-slot:leadingIcon>
            </x-form-input>
        </div>

        <x-form-select wire:model.live="typeFilter" toolparamdescription="Filter by tool type: mcp_stdio, mcp_http, built_in">
            <option value="">All Types</option>
            @foreach($types as $type)
                <option value="{{ $type->value }}">{{ $type->label() }}</option>
            @endforeach
        </x-form-select>

        <x-form-select wire:model.live="statusFilter" toolparamdescription="Filter by tool status: active, disabled">
            <option value="">All Statuses</option>
            @foreach($statuses as $status)
                <option value="{{ $status->value }}">{{ $status->label() }}</option>
            @endforeach
        </x-form-select>

        <a href="{{ route('tools.marketplace') }}"
            class="inline-flex items-center gap-1.5 rounded-lg border border-purple-300 bg-purple-50 px-4 py-2 text-sm font-medium text-purple-700 hover:bg-purple-100">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"/></svg>
            MCP Marketplace
        </a>

        <a href="{{ route('tools.templates') }}"
            class="inline-flex items-center gap-1.5 rounded-lg border border-primary-300 bg-primary-50 px-4 py-2 text-sm font-medium text-primary-700 hover:bg-primary-100">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 10V3L4 14h7v7l9-11h-7z"/></svg>
            GPU Templates
        </a>

        <a href="{{ route('tools.search-history') }}"
            class="inline-flex items-center gap-1.5 rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-medium text-indigo-700 hover:bg-indigo-100"
            title="Audit log of tool search auto-discovery events">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4"/></svg>
            Search History
        </a>

        @if($canCreate)
            <a href="{{ route('tools.create') }}"
                class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                New Tool
            </a>
        @else
            <span class="rounded-lg bg-gray-200 px-4 py-2 text-sm font-medium text-gray-400 cursor-not-allowed" title="Plan limit reached">
                New Tool
            </span>
        @endif
    </form>

    {{-- Card Grid --}}
    @if($tools->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-white px-6 py-12 text-center">
            @if($search || $typeFilter || $statusFilter)
                <p class="text-sm text-gray-400">No tools match your filters.</p>
            @else
                <svg class="mx-auto mb-3 h-10 w-10 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17l-5.21-3.01a.88.88 0 010-1.52l10.32-5.96a.88.88 0 011.32.76v11.12a.88.88 0 01-1.32.76l-5.11-2.95" />
                </svg>
                <p class="text-sm font-medium text-gray-600">No tools yet</p>
                <p class="mt-1 text-xs text-gray-400">
                    Pre-built tools (GitHub, Slack, Notion, Linear...) are added during platform setup.
                    You can also <a href="{{ route('tools.create') }}" class="text-primary-600 hover:underline">add a custom tool</a>.
                </p>
            @endif
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach($tools as $tool)
                @php
                    $isPlatform = $tool->isPlatformTool();
                    $active = $isPlatform
                        ? ($activations->get($tool->id)?->isActive() ?? false)
                        : $tool->status->value === 'active';
                @endphp
                <div class="group flex flex-col rounded-xl border border-gray-200 bg-white p-5 transition hover:border-primary-300 hover:shadow-sm">
                    {{-- Header --}}
                    <div class="mb-2 flex items-start justify-between">
                        <a href="{{ route('tools.show', $tool) }}" class="min-w-0 flex-1">
                            <h3 class="truncate font-medium text-primary-600 group-hover:text-primary-800">{{ $tool->name }}</h3>
                        </a>
                        <button wire:click="toggleStatus('{{ $tool->id }}')"
                                wire:loading.attr="disabled"
                                wire:target="toggleStatus('{{ $tool->id }}')"
                                title="{{ $active ? 'Disable' : 'Enable' }} {{ $tool->name }}"
                                class="relative ml-2 inline-flex h-5 w-9 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500
                                    {{ $active ? 'bg-primary-600' : 'bg-gray-200' }}"
                                role="switch"
                                aria-checked="{{ $active ? 'true' : 'false' }}">
                            <span class="pointer-events-none inline-block h-4 w-4 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out
                                {{ $active ? 'translate-x-4' : 'translate-x-0' }}"></span>
                        </button>
                    </div>

                    {{-- Badges --}}
                    <div class="mb-2 flex flex-wrap items-center gap-1.5">
                        <span class="inline-flex rounded-full px-2 py-0.5 text-[10px] font-medium {{ $tool->type->color() }}">
                            {{ $tool->type->label() }}
                        </span>
                        @if($isPlatform)
                            <span class="inline-flex rounded-full bg-violet-100 px-2 py-0.5 text-[10px] font-medium text-violet-700">Platform</span>
                        @endif
                        @if($tool->risk_level)
                            <span class="inline-flex rounded-full bg-gray-100 px-2 py-0.5 text-[10px] font-medium text-gray-600">{{ $tool->risk_level->label() }}</span>
                        @endif
                    </div>

                    {{-- Description --}}
                    @if($tool->description)
                        <p class="mb-3 flex-1 text-xs leading-relaxed text-gray-500 line-clamp-2">{{ $tool->description }}</p>
                    @else
                        <div class="mb-3 flex-1"></div>
                    @endif

                    {{-- Footer stats --}}
                    <div class="flex items-center justify-between border-t border-gray-100 pt-3 text-xs text-gray-400">
                        <div class="flex items-center gap-3">
                            <span title="Functions">{{ $tool->functionCount() }} {{ Str::plural('function', $tool->functionCount()) }}</span>
                            <span title="Agents">&middot; {{ $tool->agents_count }} {{ Str::plural('agent', $tool->agents_count) }}</span>
                        </div>
                        <span title="{{ $tool->created_at->toDateString() }}">{{ $tool->created_at->diffForHumans() }}</span>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <div class="mt-4">
        {{ $tools->links() }}
    </div>
</div>
