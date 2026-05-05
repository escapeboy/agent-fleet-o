<x-layouts.public
    :title="$uc['meta_title']"
    :description="$uc['meta_description']"
    :keywords="$uc['keywords']"
>
    <x-slot:head>
        @php
        $jsonLd = [
            [
                '@context' => 'https://schema.org',
                '@type'    => 'HowTo',
                'name'     => $uc['heading'],
                'description' => $uc['meta_description'],
                'url'      => url()->current(),
                'step'     => collect($uc['workflow_steps'])->map(fn($s, $i) => [
                    '@type'    => 'HowToStep',
                    'position' => $i + 1,
                    'name'     => $s['label'],
                    'text'     => $s['description'],
                ])->all(),
            ],
            [
                '@context'   => 'https://schema.org',
                '@type'      => 'FAQPage',
                'mainEntity' => collect($uc['faqs'])->map(fn($f) => [
                    '@type'          => 'Question',
                    'name'           => $f['question'],
                    'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
                ])->all(),
            ],
            [
                '@context'    => 'https://schema.org',
                '@type'       => 'BreadcrumbList',
                'itemListElement' => [
                    ['@type' => 'ListItem', 'position' => 1, 'name' => 'Use Cases', 'item' => route('use-cases.index')],
                    ['@type' => 'ListItem', 'position' => 2, 'name' => $uc['title_short'], 'item' => url()->current()],
                ],
            ],
        ];
        @endphp
        @foreach ($jsonLd as $schema)
            <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
        @endforeach
    </x-slot:head>

    <x-landing.nav />

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-12">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <div
                x-data="{ shown: false }"
                x-init="setTimeout(() => shown = true, 80)"
            >
                {{-- Breadcrumb --}}
                <div
                    class="transition duration-500 ease-out"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
                >
                    <x-use-cases.breadcrumb :title="$uc['title_short']" />
                </div>

                {{-- Category badge + heading --}}
                <div class="max-w-3xl">
                    <span
                        class="inline-flex items-center gap-2 rounded-full border {{ str_replace('border-t-', 'border-', $uc['accent']) }} {{ $uc['icon_bg'] }} px-3.5 py-1.5 text-xs font-semibold {{ $uc['icon_text'] }} mb-5 transition duration-500 ease-out"
                        :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
                        style="transition-delay: 60ms"
                    >
                        {{ $uc['title_short'] }}
                    </span>

                    <h1
                        class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl lg:text-5xl mb-4 transition duration-600 ease-out"
                        :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                        style="transition-delay: 100ms"
                    >
                        {{ $uc['heading'] }}
                    </h1>

                    <p
                        class="text-lg text-gray-600 leading-relaxed mb-6 transition duration-600 ease-out"
                        :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                        style="transition-delay: 160ms"
                    >
                        {{ $uc['subheading'] }}
                    </p>

                    <div
                        class="flex flex-col sm:flex-row gap-3 transition duration-600 ease-out"
                        :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                        style="transition-delay: 220ms"
                    >
                        <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 transition-colors">
                            Try it free
                            <i class="fa-solid fa-arrow-right text-base"></i>
                        </a>
                        <a href="{{ route('use-cases.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-5 py-2.5 text-sm font-semibold text-gray-700 hover:bg-gray-50 transition-colors">
                            All use cases
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- Workflow Diagram --}}
    <section class="py-12 bg-white border-b border-gray-100">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <div class="flex items-center gap-3 mb-2">
                <h2 class="text-xl font-bold text-gray-900">How It Works</h2>
                <span class="text-xs text-gray-400 font-medium">{{ count($uc['workflow_steps']) }}-step automated workflow</span>
            </div>
            <p class="text-sm text-gray-500 mb-4">This is the workflow FleetQ executes automatically. Every step is configurable — add approval gates, swap agents, or extend with your own tools.</p>

            <x-use-cases.workflow-diagram :steps="$uc['workflow_steps']" />
        </div>
    </section>

    {{-- Pain Points --}}
    <section class="py-12 bg-gray-50">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-12 items-center">
                <div>
                    <h2 class="text-xl font-bold text-gray-900 mb-2">Sound familiar?</h2>
                    <p class="text-sm text-gray-500 mb-6">These are the bottlenecks that FleetQ's AI agent crews are designed to eliminate.</p>
                    <ul class="space-y-4">
                        @foreach ($uc['pain_points'] as $i => $pain)
                            <li
                                x-data="{ shown: false }"
                                x-intersect.once="shown = true"
                                class="flex items-start gap-3 transition duration-500 ease-out"
                                :class="shown ? 'opacity-100 translate-x-0' : 'opacity-0 -translate-x-4'"
                                :style="'transition-delay: {{ $i * 80 }}ms'"
                            >
                                <span class="flex-shrink-0 mt-0.5 flex items-center justify-center w-5 h-5 rounded-full bg-red-100 border border-red-200">
                                    <i class="fa-solid fa-xmark text-xs text-red-500"></i>
                                </span>
                                <span class="text-sm text-gray-700 leading-relaxed">{{ $pain }}</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
                <div
                    x-data="{ shown: false }"
                    x-intersect.once="shown = true"
                    class="rounded-2xl bg-white border border-gray-200 p-8 shadow-sm transition duration-600 ease-out"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-6'"
                >
                    <h3 class="text-base font-semibold text-gray-900 mb-4">With FleetQ agent crews</h3>
                    <ul class="space-y-3">
                        @foreach ($uc['metrics'] as $metric)
                            <li class="flex items-center gap-4">
                                <span class="text-2xl font-extrabold bg-gradient-to-r from-primary-600 to-violet-600 bg-clip-text text-transparent w-16 flex-shrink-0 text-right">{{ $metric['number'] }}</span>
                                <div>
                                    <div class="text-sm font-semibold text-gray-900">{{ $metric['label'] }}</div>
                                    <div class="text-xs text-gray-500">{{ $metric['description'] }}</div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
    </section>

    {{-- Metrics Strip --}}
    <section class="py-12 bg-white">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <x-use-cases.metric-strip :metrics="$uc['metrics']" />
        </div>
    </section>

    {{-- Platform Capabilities --}}
    <section class="py-12 bg-gray-50 border-t border-gray-100">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Platform Capabilities You'll Use</h2>
            <p class="text-sm text-gray-500 mb-8">Each feature below plays a role in this use case. Click to learn more or start building immediately.</p>
            <x-use-cases.capability-grid :capabilities="$uc['capabilities']" />
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-12 bg-white border-t border-gray-100">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Frequently Asked Questions</h2>
            <p class="text-sm text-gray-500 mb-8">Common questions about using FleetQ for {{ strtolower($uc['title_short']) }} automation.</p>
            <x-use-cases.faq-section :faqs="$uc['faqs']" />
        </div>
    </section>

    {{-- Related Use Cases --}}
    <section class="py-12 bg-gray-50 border-t border-gray-100">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-2">Related Use Cases</h2>
            <p class="text-sm text-gray-500 mb-6">Teams that automate {{ strtolower($uc['title_short']) }} also use FleetQ for these workflows.</p>
            <x-use-cases.related :slugs="$uc['related']" />
        </div>
    </section>

    {{-- CTA --}}
    <x-landing.cta
        heading="Build your first {{ $uc['title_short'] }} workflow today"
        subtext="FleetQ is open source, MIT licensed, and runs on your infrastructure. Start free with no credit card."
        ctaLabel="Get started free"
        ctaHref="{{ route('register') }}"
        secondaryLabel="View all use cases"
        secondaryHref="{{ route('use-cases.index') }}"
    />

    <x-landing.footer />
</x-layouts.public>
