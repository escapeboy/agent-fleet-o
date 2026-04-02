<div>
    {{-- Header --}}
    <div class="mb-6 flex items-center justify-between">
        <div>
            <h2 class="text-xl font-bold text-gray-900">MCP Marketplace</h2>
            <p class="mt-1 text-sm text-gray-500">Browse and install MCP servers from the Smithery registry. {{ $pagination['totalCount'] ?? '300+' }} servers available.</p>
        </div>
        <div class="flex items-center gap-3">
            <a href="{{ route('tools.templates') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                GPU Templates
            </a>
            <a href="{{ route('tools.create') }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                Custom Tool
            </a>
        </div>
    </div>

    {{-- Search --}}
    <div class="mb-6">
        <input wire:model.live.debounce.400ms="search" type="search" placeholder="Search MCP servers... (e.g. postgres, github, slack)"
            class="w-full rounded-lg border border-gray-300 px-4 py-2.5 text-sm focus:border-primary-500 focus:ring-primary-500">
    </div>

    {{-- Error --}}
    @if($error)
        <div class="mb-6 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
            Could not reach MCP registry: {{ $error }}
        </div>
    @endif

    {{-- Grid --}}
    @if(empty($servers) && !$error)
        <div class="rounded-lg border border-gray-200 bg-white p-12 text-center">
            <p class="text-gray-500">No servers found{{ $search ? " matching \"{$search}\"" : '' }}.</p>
        </div>
    @else
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
            @foreach($servers as $server)
                <div class="group flex flex-col rounded-xl border border-gray-200 bg-white p-5 transition hover:border-primary-300 hover:shadow-sm">
                    <div class="mb-3 flex items-start gap-3">
                        @if($server['icon_url'])
                            <img src="{{ $server['icon_url'] }}" alt="" class="h-8 w-8 rounded" loading="lazy"
                                onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
                            <div class="hidden h-8 w-8 items-center justify-center rounded bg-gray-100 text-sm text-gray-400">MCP</div>
                        @else
                            <div class="flex h-8 w-8 items-center justify-center rounded bg-gray-100 text-sm text-gray-400">MCP</div>
                        @endif
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <h3 class="truncate font-medium text-gray-900">{{ $server['name'] }}</h3>
                                @if($server['verified'])
                                    <i class="fa-solid fa-circle-check flex-shrink-0 text-base text-blue-500"></i>
                                @endif
                            </div>
                            <div class="mt-0.5 flex items-center gap-2 text-xs text-gray-500">
                                @if($server['use_count'] > 0)
                                    <span>{{ number_format($server['use_count']) }} users</span>
                                @endif
                                @if($server['remote'])
                                    <span class="rounded-full bg-green-50 px-1.5 py-0.5 text-[10px] font-medium text-green-700">Remote</span>
                                @else
                                    <span class="rounded-full bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-600">Local</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <p class="mb-4 flex-1 text-xs leading-relaxed text-gray-500 line-clamp-2">{{ $server['description'] }}</p>

                    <button wire:click="openInstall('{{ $server['id'] }}')"
                        class="w-full rounded-lg border border-primary-200 bg-primary-50 px-3 py-1.5 text-xs font-medium text-primary-700 transition hover:bg-primary-100">
                        Install
                    </button>
                </div>
            @endforeach
        </div>

        {{-- Pagination --}}
        @if($pagination && ($pagination['totalPages'] ?? 1) > 1)
            <div class="mt-6 flex items-center justify-center gap-2">
                @if($page > 1)
                    <button wire:click="goToPage({{ $page - 1 }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Previous</button>
                @endif

                <span class="px-3 py-1.5 text-sm text-gray-500">
                    Page {{ $page }} of {{ $pagination['totalPages'] ?? '?' }}
                </span>

                @if($page < ($pagination['totalPages'] ?? 1))
                    <button wire:click="goToPage({{ $page + 1 }})" class="rounded-lg border border-gray-300 px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">Next</button>
                @endif
            </div>
        @endif
    @endif

    {{-- Install Modal --}}
    @if($showInstallModal && $selectedServer)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/50" wire:click.self="closeInstall">
            <div class="mx-4 w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
                <div class="mb-4 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        @if($selectedServer['icon_url'])
                            <img src="{{ $selectedServer['icon_url'] }}" alt="" class="h-8 w-8 rounded">
                        @endif
                        <div>
                            <h3 class="text-lg font-semibold text-gray-900">Install {{ $selectedServer['name'] }}</h3>
                            @if($selectedServer['verified'])
                                <span class="text-xs text-blue-600">Verified</span>
                            @endif
                        </div>
                    </div>
                    <button wire:click="closeInstall" class="text-gray-400 hover:text-gray-600">
                        <i class="fa-solid fa-xmark text-lg"></i>
                    </button>
                </div>

                @if($selectedServer['description'])
                    <p class="mb-4 text-sm text-gray-500">{{ $selectedServer['description'] }}</p>
                @endif

                <div class="space-y-4">
                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700">Tool Name</label>
                        <input wire:model="installName" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-primary-500 focus:ring-primary-500">
                        @error('installName') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    @if($selectedServer['remote'] && !empty($selectedServer['deployment_url']))
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">MCP Server URL</label>
                            <input wire:model="installUrl" type="url" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-primary-500 focus:ring-primary-500">
                            <p class="mt-1 text-xs text-gray-500">Remote MCP server — will connect via HTTP/SSE.</p>
                        </div>
                    @else
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">Command</label>
                            <input wire:model="installCommand" type="text" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono focus:border-primary-500 focus:ring-primary-500">
                            <p class="mt-1 text-xs text-gray-500">Local stdio MCP server — runs on your machine or bridge.</p>
                        </div>
                    @endif

                    @if(!empty($selectedServer['tools']))
                        <div>
                            <p class="mb-1.5 text-xs font-medium text-gray-500">Available Tools ({{ count($selectedServer['tools']) }})</p>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach(array_slice($selectedServer['tools'], 0, 12) as $tool)
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-600">{{ $tool['name'] ?? $tool }}</span>
                                @endforeach
                                @if(count($selectedServer['tools']) > 12)
                                    <span class="rounded-full bg-gray-100 px-2 py-0.5 text-[10px] text-gray-500">+{{ count($selectedServer['tools']) - 12 }} more</span>
                                @endif
                            </div>
                        </div>
                    @endif

                    <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
                        After installing, you may need to configure environment variables (API keys, tokens) in the tool's settings page.
                    </div>
                </div>

                <div class="mt-6 flex items-center justify-between">
                    @if($selectedServer['homepage'])
                        <a href="{{ $selectedServer['homepage'] }}" target="_blank" rel="noopener" class="text-xs text-gray-400 hover:text-gray-600">View on Smithery</a>
                    @else
                        <span></span>
                    @endif
                    <div class="flex gap-3">
                        <button wire:click="closeInstall" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">Cancel</button>
                        <button wire:click="install" class="rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white hover:bg-primary-700">
                            Install Tool
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif

    {{-- Flash --}}
    @if(session('message'))
        <div class="fixed bottom-4 right-4 z-50 rounded-lg bg-green-100 p-4 text-sm text-green-800 shadow-lg" x-data x-init="setTimeout(() => $el.remove(), 5000)">{{ session('message') }}</div>
    @endif
    @if(session('error'))
        <div class="fixed bottom-4 right-4 z-50 rounded-lg bg-red-100 p-4 text-sm text-red-800 shadow-lg" x-data x-init="setTimeout(() => $el.remove(), 5000)">{{ session('error') }}</div>
    @endif
</div>
