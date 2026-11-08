@php
    $comparisons = collect(config('comparisons'))
        ->filter(fn ($page, $slug) => $slug !== 'checked_on' && is_array($page));
@endphp
<x-layouts.public
    title="FleetQ Compared: LangGraph, CrewAI and n8n Alternatives"
    description="How FleetQ, a self-hosted control plane for AI agents, compares to LangGraph, CrewAI and n8n. Honest notes on where each tool fits best."
    keywords="langgraph alternative, crewai alternative, n8n alternative, ai agent platform comparison, fleetq comparison"
>
    <x-slot:head>
        @php
        $jsonLd = [
            '@context'    => 'https://schema.org',
            '@type'       => 'CollectionPage',
            'name'        => 'FleetQ Compared',
            'url'         => route('compare.index'),
            'description' => 'How FleetQ compares to other tools for building and running AI agents.',
            'hasPart'     => $comparisons->map(fn ($c, $slug) => [
                '@type' => 'WebPage',
                'name'  => $c['h1'],
                'url'   => route('compare.show', $slug),
            ])->values()->all(),
        ];
        @endphp
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    </x-slot:head>

    <x-landing.nav />

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-12">
        <div class="mx-auto max-w-3xl px-6 text-center lg:px-8">
            <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl mb-6">How FleetQ compares</h1>
            <p class="text-lg text-gray-600 leading-relaxed">
                FleetQ is a self-hosted control plane for running AI agents in production, with approvals, budgets, an audit trail and an MCP server.
                These pages show where it fits next to other popular tools, and where those tools are the better choice.
            </p>
        </div>
    </section>

    {{-- Comparison grid --}}
    <section class="py-12 bg-white">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @foreach ($comparisons as $slug => $c)
                    <a href="{{ route('compare.show', $slug) }}" class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-lg transition-all">
                        <span class="text-sm font-semibold text-gray-900 mb-2">FleetQ vs {{ $c['tool_name'] }}</span>
                        <p class="text-xs text-gray-600 leading-relaxed flex-1 mb-4">{{ $c['meta_description'] }}</p>
                        <span class="text-xs font-medium text-primary-600">Read the comparison <i class="fa-solid fa-arrow-right text-xs"></i></span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <x-landing.cta
        heading="See FleetQ for yourself"
        subtext="Self-host the AGPL-3.0 Community Edition, or start free on FleetQ Cloud."
        ctaLabel="Get started free"
        ctaHref="{{ route('register') }}"
        secondaryLabel="Browse use cases"
        secondaryHref="{{ route('use-cases.index') }}"
    />

    <x-landing.footer />
</x-layouts.public>
