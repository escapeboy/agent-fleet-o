<x-layouts.public
    title="AI Agent Templates | FleetQ"
    description="Browse ready-made AI agent templates for engineering, content, design, research and business. Use one in FleetQ or self-host for free."
    keywords="ai agent templates, ai agent examples, prebuilt ai agents, agent roles, open source ai agents"
>
    <x-slot:head>
        @php
        $jsonLd = [
            '@context'   => 'https://schema.org',
            '@type'      => 'CollectionPage',
            'name'       => 'AI Agent Templates | FleetQ',
            'url'        => route('templates.index'),
            'description' => 'Ready-made AI agent templates you can use in FleetQ or self-host.',
            'mainEntity' => [
                '@type'           => 'ItemList',
                'itemListElement' => $templates->values()->map(fn ($t, $i) => [
                    '@type'    => 'ListItem',
                    'position' => $i + 1,
                    'name'     => $t['name'],
                    'url'      => route('templates.show', $t['slug']),
                ])->all(),
            ],
        ];
        @endphp
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    </x-slot:head>

    <x-landing.nav />

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-12">
        <div class="mx-auto max-w-3xl px-6 lg:px-8 text-center">
            <h1 class="text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl mb-6">Agent Templates</h1>
            <p class="text-lg text-gray-600 leading-relaxed mb-10">
                Start from a ready-made agent with a role, a goal and a set of skills already defined.
                Customize it to fit your work, or run it on your own infrastructure.
            </p>
            <div class="flex flex-col sm:flex-row items-center justify-center gap-4">
                @if (Route::has('register'))
                    <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 transition-colors">
                        Start free
                        <i class="fa-solid fa-arrow-right text-base"></i>
                    </a>
                @endif
                <a href="{{ route('docs.show', 'getting-started') }}" class="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 transition-colors">
                    Self-host for free
                </a>
            </div>
        </div>
    </section>

    {{-- Templates by category --}}
    <section class="py-12 bg-white">
        <div class="mx-auto max-w-7xl px-6 lg:px-8 space-y-12">
            @foreach ($categories as $category => $items)
                <div>
                    <h2 class="text-xl font-bold text-gray-900 mb-5">{{ ucfirst($category) }}</h2>
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-5">
                        @foreach ($items as $template)
                            <a href="{{ route('templates.show', $template['slug']) }}" class="group flex flex-col rounded-2xl border border-gray-200 bg-white p-6 shadow-sm hover:shadow-lg transition-all duration-300 ease-out">
                                <h3 class="text-base font-semibold text-gray-900">{{ $template['name'] }}</h3>
                                <p class="mt-1 text-xs font-medium text-primary-600">{{ $template['role'] }}</p>
                                <p class="mt-3 text-sm text-gray-600 leading-relaxed flex-1">{{ $template['goal'] }}</p>
                                <div class="mt-4 flex items-center gap-1 text-xs font-medium text-primary-600 group-hover:gap-2 transition-all">
                                    View template
                                    <i class="fa-solid fa-arrow-right text-xs"></i>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    <x-landing.footer />
</x-layouts.public>
