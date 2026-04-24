<div class="max-w-7xl mx-auto py-8 px-4 sm:px-6 lg:px-8">
    <div class="mb-4">
        <a href="{{ route('external-agents.index') }}" class="text-sm text-gray-500 hover:text-gray-700">← External agents</a>
    </div>

    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-2xl font-semibold text-gray-900">Agentverse Marketplace</h1>
            <p class="text-sm text-gray-600 mt-1">
                Browse specialist agents on Fetch.ai's <span class="font-mono text-xs">Agentverse</span> and install them into your team. Once installed, the agent is callable from workflows, crews, and the assistant.
            </p>
        </div>
    </div>

    @if ($credentialMissing)
        <div class="rounded-lg border border-yellow-300 bg-yellow-50 p-6">
            <h3 class="text-sm font-semibold text-yellow-900">ASI:One API key required</h3>
            <p class="mt-1 text-sm text-yellow-800">
                To browse and install from Agentverse, your team needs an ASI:One API key. Create one at
                <a href="https://asi1.ai/" target="_blank" class="underline">asi1.ai</a>, then add it here as a
                Credential with provider <code class="font-mono text-xs bg-yellow-100 px-1 rounded">agentverse</code>.
            </p>
            <a href="{{ route('credentials.create') }}"
               class="mt-4 inline-flex items-center rounded-lg bg-yellow-600 px-3 py-2 text-sm font-medium text-white hover:bg-yellow-700">
                Add credential
            </a>
        </div>
    @else
        @if (session('success'))
            <div class="mb-4 p-3 bg-green-50 border border-green-200 rounded-lg text-sm text-green-800">{{ session('success') }}</div>
        @endif
        @if ($errorMessage)
            <div class="mb-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-800">{{ $errorMessage }}</div>
        @endif

        <div class="bg-white shadow rounded-lg p-4 mb-6 flex gap-3">
            <x-form-input wire:model.live.debounce.500ms="search" placeholder="Search agents" compact />
            <x-form-input wire:model.live.debounce.500ms="category" placeholder="Category filter" compact />
            <button wire:click="refreshAgents"
                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50">
                Refresh
            </button>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @forelse ($agents ?? [] as $agent)
                <div class="bg-white shadow rounded-lg p-5 flex flex-col">
                    <div class="flex items-start justify-between mb-3">
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">{{ $agent['name'] ?? 'Unnamed' }}</h3>
                            @if (!empty($agent['handle']))
                                <p class="text-xs text-gray-500 font-mono">{{ $agent['handle'] }}</p>
                            @endif
                        </div>
                        @if (isset($agent['ranking_score']))
                            <span class="rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
                                ★ {{ number_format((float) $agent['ranking_score'], 1) }}
                            </span>
                        @endif
                    </div>

                    <p class="text-sm text-gray-600 mb-4 flex-1">
                        {{ \Illuminate\Support\Str::limit((string) ($agent['readme'] ?? $agent['description'] ?? 'No description provided.'), 140) }}
                    </p>

                    <div class="text-xs font-mono text-gray-400 mb-3 truncate" title="{{ $agent['address'] ?? '' }}">
                        {{ $agent['address'] ?? '' }}
                    </div>

                    <button wire:click="install('{{ $agent['address'] ?? '' }}')"
                            wire:loading.attr="disabled"
                            class="w-full rounded-lg bg-primary-600 px-3 py-2 text-sm font-medium text-white hover:bg-primary-700 disabled:opacity-50">
                        Install into team
                    </button>
                </div>
            @empty
                <div class="col-span-full rounded-lg border border-dashed border-gray-300 bg-white p-12 text-center">
                    <p class="text-sm text-gray-500">
                        @if ($agents === null)
                            Loading agents…
                        @else
                            No agents matched your filters. Try a broader search.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>
    @endif
</div>
