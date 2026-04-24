<div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="mb-4">
        <a href="{{ route('external-agents.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← External agents</a>
    </div>

    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Agentverse Marketplace</h1>
            <p class="text-sm text-gray-600 mt-1">
                Browse specialist agents on Fetch.ai's <span class="font-mono text-xs">Agentverse</span> and install them into your team. Public catalog — no credential required.
            </p>
        </div>
    </div>

    @if (session('success'))
        <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">{{ session('success') }}</div>
    @endif
    @if ($errorMessage)
        <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ $errorMessage }}</div>
    @endif

    <div class="bg-white shadow rounded-lg p-4 mb-6 flex gap-3">
        <x-form-input wire:model.live.debounce.500ms="search" placeholder="Search (e.g. weather, travel, research)…" compact />
        <button wire:click="refreshAgents"
                wire:loading.attr="disabled"
                class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50">
            <span wire:loading.remove wire:target="refreshAgents,search">Refresh</span>
            <span wire:loading wire:target="refreshAgents,search">Loading…</span>
        </button>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse ($agents ?? [] as $agent)
            <div class="bg-white shadow rounded-lg p-5 flex flex-col">
                <div class="flex items-start gap-3 mb-3">
                    @if (!empty($agent['avatar_href']))
                        <img src="{{ $agent['avatar_href'] }}" alt=""
                             class="w-12 h-12 rounded-lg object-cover bg-gray-100 flex-shrink-0"
                             onerror="this.style.visibility='hidden'"/>
                    @else
                        <div class="w-12 h-12 rounded-lg bg-gradient-to-br from-primary-500 to-primary-700 flex-shrink-0 flex items-center justify-center text-white font-semibold">
                            {{ strtoupper(substr($agent['name'] ?? '?', 0, 1)) }}
                        </div>
                    @endif

                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <h3 class="text-base font-semibold text-gray-900 truncate">{{ $agent['name'] ?? 'Unnamed' }}</h3>
                            @if (!empty($agent['featured']))
                                <span class="text-xs bg-yellow-100 text-yellow-800 px-1.5 py-0.5 rounded font-medium">★ Featured</span>
                            @endif
                        </div>
                        @if (!empty($agent['handle']))
                            <p class="text-xs text-gray-500 font-mono truncate">{{ $agent['handle'] }}</p>
                        @elseif (!empty($agent['domain']))
                            <p class="text-xs text-gray-500 font-mono truncate">{{ $agent['domain'] }}</p>
                        @endif
                    </div>

                    @if (isset($agent['rating']) && $agent['rating'] > 0)
                        <div class="text-right flex-shrink-0">
                            <div class="text-xs font-semibold text-blue-700">{{ number_format((float) $agent['rating'], 1) }}</div>
                            <div class="text-xs text-gray-400">★ rating</div>
                        </div>
                    @endif
                </div>

                <p class="text-sm text-gray-600 mb-3 line-clamp-3 flex-1">
                    {{ \Illuminate\Support\Str::limit(strip_tags((string) ($agent['description'] ?? $agent['readme'] ?? 'No description provided.')), 180) }}
                </p>

                <div class="flex items-center gap-2 mb-3 text-xs text-gray-500">
                    @if (!empty($agent['category']))
                        <span class="rounded bg-gray-100 px-1.5 py-0.5">{{ $agent['category'] }}</span>
                    @endif
                    @if (!empty($agent['total_interactions']))
                        <span>{{ number_format((int) $agent['total_interactions']) }} interactions</span>
                    @endif
                </div>

                <div class="text-xs font-mono text-gray-400 mb-3 truncate" title="{{ $agent['address'] ?? '' }}">
                    {{ \Illuminate\Support\Str::limit((string) ($agent['address'] ?? ''), 40) }}
                </div>

                <button wire:click="install('{{ $agent['address'] ?? '' }}')"
                        wire:loading.attr="disabled"
                        wire:target="install"
                        class="w-full rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                    Install into team
                </button>
            </div>
        @empty
            <div class="col-span-full rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
                <p class="text-sm text-gray-500">
                    @if ($loading || $agents === null)
                        Loading agents from Agentverse…
                    @else
                        No agents matched "{{ $search }}". Try a broader search.
                    @endif
                </p>
            </div>
        @endforelse
    </div>
</div>
