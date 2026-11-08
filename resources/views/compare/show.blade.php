<x-layouts.public
    :title="$c['meta_title']"
    :description="$c['meta_description']"
    :keywords="$c['keywords']"
>
    <x-slot:head>
        @php
        $jsonLd = [
            [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => collect($c['faqs'])->map(fn ($f) => [
                    '@type'          => 'Question',
                    'name'           => $f['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
                ])->values()->all(),
            ],
            [
                '@context'        => 'https://schema.org',
                '@type'           => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Compare', 'item' => route('compare.index')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => 'FleetQ vs '.$c['tool_name'], 'item' => route('compare.show', $slug)],
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
            <nav aria-label="Breadcrumb" class="mb-6 text-sm text-gray-500">
                <a href="{{ route('compare.index') }}" class="hover:text-gray-700">Compare</a>
                <span aria-hidden="true" class="mx-2">/</span>
                <span class="text-gray-700">FleetQ vs {{ $c['tool_name'] }}</span>
            </nav>
            <div class="max-w-3xl">
                <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl lg:text-5xl mb-6">
                    {{ $c['h1'] }}
                </h1>
                @foreach ($c['intro'] as $paragraph)
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
                <div class="rounded-2xl border border-gray-200 bg-gray-50 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-2">When {{ $c['tool_name'] }} is the better fit</h3>
                    <p class="text-sm text-gray-700 leading-relaxed">{{ $c['short_answer']['pick_tool'] }}</p>
                </div>
                <div class="rounded-2xl border border-primary-200 bg-primary-50 p-6">
                    <h3 class="text-base font-semibold text-gray-900 mb-2">When FleetQ is the better fit</h3>
                    <p class="text-sm text-gray-700 leading-relaxed">{{ $c['short_answer']['pick_fleetq'] }}</p>
                </div>
            </div>
        </div>
    </section>

    {{-- Comparison table --}}
    <section class="py-12 bg-gray-50">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-6">FleetQ and {{ $c['tool_name'] }} side by side</h2>
            <div class="overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm">
                <table class="min-w-full divide-y divide-gray-200 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-500"><span class="sr-only">Feature</span></th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-900">FleetQ</th>
                            <th scope="col" class="px-4 py-3 text-left font-semibold text-gray-900">{{ $c['tool_name'] }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        @foreach ($c['table'] as $row)
                            <tr>
                                <th scope="row" class="px-4 py-3 text-left font-semibold text-gray-900">{{ $row['label'] }}</th>
                                <td class="px-4 py-3 text-gray-700">{{ $row['fleetq'] }}</td>
                                <td class="px-4 py-3 text-gray-700">{{ $row['tool'] }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-3 text-xs text-gray-500">Checked on {{ $checkedOn }}. {{ $c['tool_name'] }} changes over time, so its own docs are the source of truth.</p>
        </div>
    </section>

    {{-- Body sections --}}
    @foreach ($c['sections'] as $section)
        <section class="py-12 {{ $loop->even ? 'bg-gray-50' : 'bg-white' }} border-t border-gray-100">
            <div class="mx-auto max-w-3xl px-6 lg:px-8">
                <h2 class="text-xl font-bold text-gray-900 mb-4">{{ $section['heading'] }}</h2>
                @foreach ($section['paragraphs'] as $paragraph)
                    <p class="text-base text-gray-700 leading-relaxed mb-4">{{ $paragraph }}</p>
                @endforeach
            </div>
        </section>
    @endforeach

    @if (! empty($c['together']))
        <section class="py-12 bg-white border-t border-gray-100">
            <div class="mx-auto max-w-3xl px-6 lg:px-8">
                <h2 class="text-xl font-bold text-gray-900 mb-4">Using {{ $c['tool_name'] }} and FleetQ together</h2>
                @foreach ($c['together'] as $paragraph)
                    <p class="text-base text-gray-700 leading-relaxed mb-4">{{ $paragraph }}</p>
                @endforeach
            </div>
        </section>
    @endif

    {{-- Docs --}}
    <section class="py-12 bg-white border-t border-gray-100">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Read more in the docs</h2>
            <ul class="space-y-2 text-base">
                <li><a href="{{ route('docs.show', 'agents') }}" class="font-medium text-primary-600 hover:text-primary-700">Agents in FleetQ</a></li>
                <li><a href="{{ route('docs.show', 'mcp-server') }}" class="font-medium text-primary-600 hover:text-primary-700">The FleetQ MCP server</a></li>
            </ul>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-12 bg-gray-50 border-t border-gray-100">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-6">Frequently asked questions</h2>
            <dl class="space-y-6">
                @foreach ($c['faqs'] as $faq)
                    <div>
                        <dt class="text-base font-semibold text-gray-900">{{ $faq['question'] }}</dt>
                        <dd class="mt-2 text-sm text-gray-700 leading-relaxed">{{ $faq['answer'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    {{-- CTA --}}
    <section class="bg-gradient-to-r from-primary-700 via-primary-600 to-violet-700 py-16 sm:py-20">
        <div class="mx-auto max-w-4xl px-6 text-center lg:px-8">
            <h2 class="text-3xl font-bold tracking-tight text-white">Try FleetQ your way</h2>
            <p class="mx-auto mt-4 max-w-xl text-lg text-primary-100">Self-host the AGPL-3.0 Community Edition, or start on FleetQ Cloud for free.</p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="https://github.com/escapeboy/agent-fleet-o"
                   data-analytics-event="cta_click" data-analytics-label="self_host_github"
                   target="_blank" rel="noopener noreferrer"
                   class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-primary-600 shadow-sm transition hover:bg-primary-50">
                    Self-host from GitHub
                </a>
                <a href="https://fleetq.net"
                   data-analytics-event="cta_click" data-analytics-label="try_cloud"
                   target="_blank" rel="noopener noreferrer"
                   class="rounded-lg border border-white/60 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                    Try FleetQ Cloud
                </a>
            </div>
        </div>
    </section>

    <x-landing.footer />
</x-layouts.public>
