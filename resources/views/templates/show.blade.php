<x-layouts.public
    :title="$template['name'].' AI Agent Template | FleetQ'"
    :description="Str::limit($template['goal'], 150)"
    :keywords="strtolower($template['name']).' ai agent, '.$template['category'].' ai agent template, ai agent templates'"
>
    <x-slot:head>
        @php
        $jsonLd = [
            '@context'        => 'https://schema.org',
            '@type'           => 'BreadcrumbList',
            'itemListElement' => [
                ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => url('/')],
                ['@type' => 'ListItem', 'position' => 2, 'name' => 'Templates', 'item' => route('templates.index')],
                ['@type' => 'ListItem', 'position' => 3, 'name' => $template['name'], 'item' => route('templates.show', $template['slug'])],
            ],
        ];
        $personality = $template['personality'] ?? [];
        @endphp
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    </x-slot:head>

    <x-landing.nav />

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-12">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <nav aria-label="Breadcrumb" class="mb-6 text-sm text-gray-500">
                <a href="{{ url('/') }}" class="hover:text-gray-700">Home</a>
                <span aria-hidden="true" class="mx-2">/</span>
                <a href="{{ route('templates.index') }}" class="hover:text-gray-700">Templates</a>
                <span aria-hidden="true" class="mx-2">/</span>
                <span class="text-gray-700">{{ $template['name'] }}</span>
            </nav>
            <div class="max-w-3xl">
                <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl lg:text-5xl mb-3">{{ $template['name'] }}</h1>
                <p class="text-lg font-medium text-primary-600 mb-4">{{ $template['role'] }}</p>
                <p class="text-lg text-gray-600 leading-relaxed mb-6">{{ $template['goal'] }}</p>

                <div class="flex flex-col sm:flex-row gap-3">
                    <a href="{{ route('agents.create', ['template' => $template['slug']]) }}" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 transition-colors">
                        Use this template
                        <i class="fa-solid fa-arrow-right text-base"></i>
                    </a>
                    <a href="{{ route('docs.show', 'getting-started') }}" class="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                        Self-host for free
                    </a>
                </div>
                <p class="mt-3 text-xs text-gray-500">You will be asked to sign in or create an account.</p>
            </div>
        </div>
    </section>

    {{-- Details --}}
    <section class="py-12 bg-white border-t border-gray-100">
        <div class="mx-auto max-w-5xl px-6 lg:px-8 space-y-10">
            <div>
                <h2 class="text-xl font-bold text-gray-900 mb-3">Backstory</h2>
                <p class="text-sm text-gray-600 leading-relaxed">{{ $template['backstory'] }}</p>
            </div>

            <div>
                <h2 class="text-xl font-bold text-gray-900 mb-3">Capabilities</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($template['capabilities'] ?? [] as $capability)
                        <li class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-medium text-gray-700">{{ str_replace('_', ' ', $capability) }}</li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2 class="text-xl font-bold text-gray-900 mb-3">Skills</h2>
                <ul class="flex flex-wrap gap-2">
                    @foreach ($template['skills'] ?? [] as $skill)
                        <li class="rounded-full border border-primary-200 bg-primary-50 px-3 py-1 text-xs font-medium text-primary-700">{{ $skill }}</li>
                    @endforeach
                </ul>
            </div>

            <div>
                <h2 class="text-xl font-bold text-gray-900 mb-3">Personality</h2>
                <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
                    <div>
                        <dt class="font-semibold text-gray-900">Tone</dt>
                        <dd class="text-gray-600">{{ ucfirst($personality['tone'] ?? '') }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-900">Communication style</dt>
                        <dd class="text-gray-600">{{ ucfirst($personality['communication_style'] ?? '') }}</dd>
                    </div>
                    <div>
                        <dt class="font-semibold text-gray-900">Provider</dt>
                        <dd class="text-gray-600">{{ ucfirst($template['provider'] ?? '') }}</dd>
                    </div>
                </dl>
                <ul class="mt-4 flex flex-wrap gap-2">
                    @foreach ($personality['traits'] ?? [] as $trait)
                        <li class="rounded-full border border-gray-200 bg-gray-50 px-3 py-1 text-xs font-medium text-gray-700">{{ $trait }}</li>
                    @endforeach
                </ul>
            </div>

            <a href="{{ route('templates.index') }}" class="inline-flex items-center gap-2 text-sm font-medium text-primary-600 hover:text-primary-700">
                <i class="fa-solid fa-arrow-left text-xs"></i>
                Back to all templates
            </a>
        </div>
    </section>

    <x-landing.footer />
</x-layouts.public>
