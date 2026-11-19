<x-layouts.public
    title="FleetQ MCP Tools: Full Catalog of Agent Tools"
    description="Every MCP tool FleetQ exposes to AI agents, grouped by area: agents, crews, workflows, signals, approvals, budgets, memory and more."
    keywords="fleetq mcp tools, mcp server tools, model context protocol tools, ai agent tools, mcp tool catalog"
>
    <x-slot:head>
        @php
        $jsonLd = [
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => 'FleetQ MCP Tools',
            'url'         => route('mcp-tools.index'),
            'description' => 'Catalog of the Model Context Protocol tools FleetQ exposes to AI agents.',
            'hasPart'     => array_map(fn ($g) => [
                '@type' => 'WebPageElement',
                'name'  => $g['group'],
                'url'   => route('mcp-tools.index').'#'.$g['slug'],
            ], $groups),
        ];
        @endphp
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    </x-slot:head>

    <x-landing.nav />

    <div x-data="{ q: '' }">
        {{-- Hero --}}
        <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-10">
            <div class="mx-auto max-w-3xl px-6 text-center lg:px-8">
                <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl mb-6">FleetQ MCP tools</h1>
                <p class="text-lg text-gray-600 leading-relaxed">
                    FleetQ ships a Model Context Protocol server with {{ $total }} tools across {{ count($groups) }} areas.
                    Point Claude, Cursor, Codex or your own agent at it, and anything a person can do in the UI, an agent can do too.
                </p>
                <div class="mt-8">
                    <label for="mcp-tool-filter" class="sr-only">Filter tools</label>
                    <input id="mcp-tool-filter" type="search" x-model="q" placeholder="Filter by name or description…"
                           class="w-full rounded-lg border border-gray-300 px-4 py-3 text-sm shadow-sm focus:border-primary-500 focus:ring-primary-500">
                </div>
            </div>
        </section>

        {{-- Jump list --}}
        <nav aria-label="Tool groups" class="bg-white" x-show="q === ''">
            <div class="mx-auto max-w-5xl px-6 lg:px-8">
                <ul class="flex flex-wrap gap-2">
                    @foreach ($groups as $group)
                        <li>
                            <a href="#{{ $group['slug'] }}" class="inline-block rounded-full border border-gray-200 px-3 py-1 text-xs text-gray-700 hover:border-primary-300 hover:text-primary-600">
                                {{ $group['group'] }} <span class="text-gray-400">({{ count($group['tools']) }})</span>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </div>
        </nav>

        {{-- Groups --}}
        <section class="py-10 bg-white">
            <div class="mx-auto max-w-5xl px-6 lg:px-8 space-y-10">
                @forelse ($groups as $group)
                    <div id="{{ $group['slug'] }}" class="scroll-mt-24">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">{{ $group['group'] }}</h2>
                        <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200">
                            @foreach ($group['tools'] as $tool)
                                <li class="px-4 py-3"
                                    data-search="{{ mb_strtolower($tool['name'].' '.$tool['description']) }}"
                                    x-show="q === '' || $el.dataset.search.includes(q.toLowerCase())">
                                    <code class="text-sm font-semibold text-primary-700">{{ $tool['name'] }}</code>
                                    @if ($tool['description'] !== '')
                                        <p class="mt-1 text-sm text-gray-600">{{ $tool['description'] }}</p>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <p class="text-center text-gray-600">The tool catalog is not available right now.</p>
                @endforelse
            </div>
        </section>

        {{-- CTA --}}
        <section class="bg-primary-600 py-16">
            <div class="mx-auto max-w-3xl px-6 text-center lg:px-8">
                <h2 class="text-3xl font-bold tracking-tight text-white">Connect your agent to FleetQ</h2>
                <p class="mx-auto mt-4 max-w-xl text-lg text-primary-100">Self-host the AGPL-3.0 Community Edition, or start on FleetQ Cloud for free.</p>
                <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                    <a href="{{ route('register') }}"
                       data-analytics-event="cta_click" data-analytics-label="try_cloud"
                       class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-primary-600 shadow-sm transition hover:bg-primary-50">
                        Get started free
                    </a>
                    <a href="https://github.com/escapeboy/agent-fleet-o"
                       data-analytics-event="cta_click" data-analytics-label="self_host_github"
                       target="_blank" rel="noopener noreferrer"
                       class="rounded-lg border border-white/60 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                        Self-host from GitHub
                    </a>
                </div>
                <p class="mt-6 text-sm text-primary-100">
                    <a href="{{ route('docs.show', 'mcp-server') }}" class="underline underline-offset-2 hover:text-white">Read the MCP server docs</a>
                    to connect over stdio or HTTP/SSE.
                </p>
            </div>
        </section>
    </div>

    <x-landing.footer />
</x-layouts.public>
