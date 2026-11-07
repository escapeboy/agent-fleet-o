<x-layouts.public
    :title="$comparison['meta_title']"
    :description="$comparison['meta_description']"
    :keywords="$comparison['keywords']"
>
    <x-slot:head>
        @php
        $jsonLd = [
            [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => collect($comparison['faqs'])->map(fn($f) => [
                    '@type'          => 'Question',
                    'name'           => $f['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
                ])->all(),
            ],
            [
                '@context'    => 'https://schema.org',
                '@type'       => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Compare', 'item' => route('compare.index')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'FleetQ vs '.$comparison['tool_name'], 'item' => url()->current()],
                ],
            ],
        ];
        @endphp
        @foreach ($jsonLd as $schema)
            <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        @endforeach
    </x-slot:head>

    <x-landing.nav />

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-12">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <nav class="flex items-center gap-2 text-sm text-gray-500 mb-6" aria-label="Breadcrumb">
                <a href="{{ route('compare.index') }}" class="hover:text-primary-600 transition-colors">Compare</a>
                <i class="fa-solid fa-chevron-right text-base flex-shrink-0"></i>
                <span class="text-gray-900 font-medium truncate">FleetQ vs {{ $comparison['tool_name'] }}</span>
            </nav>

            <div class="max-w-3xl">
                <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl lg:text-5xl mb-6">
                    {{ $comparison['h1'] }}
                </h1>
                @foreach ($comparison['intro'] as $paragraph)
                    <p class="text-lg text-gray-600 leading-relaxed mb-4">{{ $paragraph }}</p>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Short answer --}}
    <section class="py-12 bg-white border-b border-gray-100">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-6">The short answer</h2>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="rounded-2xl border border-gray-200 bg-white p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900 mb-2">When {{ $comparison['tool_name'] }} is the better fit</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $comparison['short_answer']['pick_tool'] }}</p>
                </div>
                <div class="rounded-2xl border border-primary-200 bg-primary-50 p-6 shadow-sm">
                    <h3 class="text-base font-semibold text-gray-900 mb-2">When FleetQ is the better fit</h3>
                    <p class="text-sm text-gray-600 leading-relaxed">{{ $comparison['short_answer']['pick_fleetq'] }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Comparison table --}}
    <section class="py-12 bg-gray-50">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-6">FleetQ vs {{ $comparison['tool_name'] }} at a glance</h2>
            <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-5 py-3 text-left font-semibold text-gray-900"><span class="sr-only">Feature</span></th>
                            <th scope="col" class="px-5 py-3 text-left font-semibold text-gray-900">FleetQ</th>
                            <th scope="col" class="px-5 py-3 text-left font-semibold text-gray-900">{{ $comparison['tool_name'] }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($comparison['table'] as $row)
                            <tr>
                                <th scope="row" class="px-5 py-3 text-left font-medium text-gray-900 align-top">{{ $row['label'] }}</th>
                                <td class="px-5 py-3 text-gray-700 align-top">{{ $row['fleetq'] }}</td>
                                <td class="px-5 py-3 text-gray-500 align-top">{{ $row['tool'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">Checked on {{ $checkedOn }}. FleetQ details come from our own code and documentation.</p>
        </div>
    </section>

    {{-- Using both together --}}
    @if (! empty($comparison['together']))
        <section class="py-12 bg-white border-b border-gray-100">
            <div class="mx-auto max-w-5xl px-6 lg:px-8">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Using FleetQ and {{ $comparison['tool_name'] }} together</h2>
                <div class="max-w-3xl space-y-4">
                    @foreach ($comparison['together'] as $paragraph)
                        <p class="text-gray-600 leading-relaxed">{{ $paragraph }}</p>
                    @endforeach
                </div>
            </div>
        </section>
    @endif

    {{-- Body sections --}}
    @foreach ($comparison['sections'] as $section)
        <section class="py-12 {{ $loop->even ? 'bg-gray-50' : 'bg-white' }} border-b border-gray-100">
            <div class="mx-auto max-w-5xl px-6 lg:px-8">
                <h2 class="text-xl font-bold text-gray-900 mb-4">{{ $section['heading'] }}</h2>
                <div class="max-w-3xl space-y-4">
                    @foreach ($section['paragraphs'] as $paragraph)
                        <p class="text-gray-600 leading-relaxed">{{ $paragraph }}</p>
                    @endforeach
                </div>
            </div>
        </section>
    @endforeach

    {{-- Docs links --}}
    <section class="py-12 bg-white">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Read the docs</h2>
            <ul class="flex flex-col sm:flex-row gap-3 text-sm">
                <li><a href="{{ route('docs.show', 'agents') }}" class="font-semibold text-primary-600 hover:text-primary-700">Agents documentation</a></li>
                <li><a href="{{ route('docs.show', 'mcp-server') }}" class="font-semibold text-primary-600 hover:text-primary-700">MCP server documentation</a></li>
            </ul>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-12 bg-gray-50 border-t border-gray-100">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Frequently Asked Questions</h2>
            <x-use-cases.faq-section :faqs="$comparison['faqs']" />
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-gradient-to-r from-primary-700 via-primary-600 to-violet-700 py-20">
        <div class="mx-auto max-w-4xl px-6 text-center lg:px-8">
            <h2 class="text-3xl font-bold tracking-tight text-white">Run your agents with guardrails</h2>
            <p class="mx-auto mt-4 max-w-xl text-lg text-primary-100">Self-host the AGPL-3.0 Community Edition for free, or start on FleetQ Cloud.</p>
            <div class="mt-10 flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="https://github.com/escapeboy/agent-fleet-o"
                   data-analytics-event="cta_click" data-analytics-label="self_host_github"
                   class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-primary-600 shadow-sm transition hover:bg-primary-50"
                   target="_blank" rel="noopener noreferrer">
                    Self-host from GitHub
                </a>
                <a href="{{ route('register') }}"
                   data-analytics-event="cta_click" data-analytics-label="try_cloud"
                   class="rounded-lg border border-white/60 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                    Try FleetQ Cloud
                </a>
            </div>
            <p class="mt-6 text-sm text-primary-100">
                <a href="{{ route('compare.index') }}" class="underline underline-offset-2 hover:text-white">See all comparisons</a>
            </p>
        </div>
    </section>

    <x-landing.footer />
</x-layouts.public>
