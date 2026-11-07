@php
    $comparisons = \App\Http\Controllers\ComparisonsController::pages();
@endphp
<x-layouts.public
    title="Compare FleetQ with LangGraph, CrewAI and n8n"
    description="Honest comparisons of FleetQ, the open-source AI agent control plane, with LangGraph, CrewAI and n8n. See where each tool fits best."
    keywords="langgraph alternative, crewai alternative, n8n alternative, ai agent platform comparison, fleetq comparison"
>
    <x-slot:head>
        @php
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'CollectionPage',
            'name'     => 'Compare FleetQ',
            'url'      => route('compare.index'),
            'hasPart'  => collect($comparisons)->map(fn (array $comparison, string $slug) => [
                '@type' => 'WebPage',
                'name'  => $comparison['meta_title'],
                'url'   => route('compare.show', $slug),
            ])->values()->all(),
        ];
        @endphp
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    </x-slot:head>

    <x-landing.nav />

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-12">
        <div class="mx-auto max-w-5xl px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl mb-6">Compare FleetQ</h1>
            <p class="mx-auto max-w-2xl text-lg text-gray-600 leading-relaxed">
                FleetQ is an open-source, self-hosted control plane for running AI agents in production.
                These pages explain honestly where it differs from other popular tools, and when the other tool is the better choice.
            </p>
        </div>
    </section>

    {{-- Comparison grid --}}
    <section class="py-12 bg-white">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-5">
                @foreach ($comparisons as $slug => $comparison)
                    <a href="{{ route('compare.show', $slug) }}"
                       class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-lg transition-all duration-300 ease-out">
                        <span class="text-base font-semibold text-gray-900 mb-2">FleetQ vs {{ $comparison['tool_name'] }}</span>
                        <p class="text-sm text-gray-600 leading-relaxed flex-1 mb-4">{{ $comparison['meta_description'] }}</p>
                        <span class="flex items-center gap-1 text-xs font-medium text-primary-600 group-hover:gap-2 transition-all">
                            Read the comparison
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    <x-landing.cta
        heading="Run your agents with guardrails"
        subtext="FleetQ is open source, AGPL-3.0 licensed, and runs on your infrastructure."
        ctaLabel="Get started free"
        ctaHref="{{ route('register') }}"
        secondaryLabel="Browse use cases"
        secondaryHref="{{ route('use-cases.index') }}"
    />

    <x-landing.footer />
</x-layouts.public>
