<x-layouts.public
    title="AI Agent Use Cases — What You Can Build with FleetQ"
    description="Explore how teams use FleetQ AI agent crews to automate content marketing, customer support, sales, data research, compliance, and more. Start free."
    keywords="ai agent use cases, automate business workflows, ai automation examples, agent fleet use cases, ai workflow automation"
>
    <x-slot:head>
        @php
        $jsonLd = [
            '@context' => 'https://schema.org',
            '@type'    => 'CollectionPage',
            'name'     => 'AI Agent Use Cases | FleetQ',
            'url'      => route('use-cases.index'),
            'description' => 'Discover how businesses automate complex workflows with FleetQ AI agent crews.',
            'hasPart'  => collect(config('use_cases'))->map(fn($uc, $slug) => [
                '@type' => 'WebPage',
                'name'  => $uc['heading'],
                'url'   => route('use-cases.show', $slug),
            ])->values()->all(),
        ];
        @endphp
        <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) !!}</script>
    </x-slot:head>

    <x-landing.nav />

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-16">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div
                x-data="{ shown: false }"
                x-init="setTimeout(() => shown = true, 100)"
                class="mx-auto max-w-3xl text-center"
            >
                <span
                    class="inline-flex items-center gap-2 rounded-full border border-primary-200 bg-primary-50 px-4 py-1.5 text-xs font-semibold text-primary-700 mb-6 transition duration-600 ease-out"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-2'"
                >
                    <span class="relative flex h-2 w-2">
                        <span class="absolute inline-flex h-full w-full rounded-full bg-primary-400 opacity-75 animate-ping"></span>
                        <span class="relative inline-flex h-2 w-2 rounded-full bg-primary-500"></span>
                    </span>
                    Workflows that run while you sleep
                </span>
                <h1
                    class="text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl lg:text-6xl mb-6 transition duration-700 ease-out"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                    style="transition-delay: 80ms"
                >
                    What Can You Build with
                    <span class="bg-gradient-to-r from-primary-600 to-violet-600 bg-clip-text text-transparent"> AI Agent Crews?</span>
                </h1>
                <p
                    class="text-lg text-gray-600 leading-relaxed mb-10 transition duration-700 ease-out"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                    style="transition-delay: 150ms"
                >
                    FleetQ orchestrates specialised AI agents into automated pipelines.
                    Explore how teams across industries eliminate manual work and ship better results, faster.
                </p>
                <div
                    class="flex flex-col sm:flex-row items-center justify-center gap-4 transition duration-700 ease-out"
                    :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                    style="transition-delay: 220ms"
                >
                    <a href="{{ route('register') }}" class="inline-flex items-center gap-2 rounded-xl bg-primary-600 px-6 py-3 text-sm font-semibold text-white shadow-sm hover:bg-primary-700 transition-colors">
                        Start free — no credit card
                        <i class="fa-solid fa-arrow-right text-base"></i>
                    </a>
                    <a href="{{ route('marketplace.index') }}" class="inline-flex items-center gap-2 rounded-xl border border-gray-300 bg-white px-6 py-3 text-sm font-semibold text-gray-700 shadow-sm hover:bg-gray-50 transition-colors">
                        Browse Marketplace
                    </a>
                </div>
            </div>
        </div>
    </section>

    {{-- Use Case Grid --}}
    <section class="py-16 bg-white">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <h2 class="text-2xl font-bold text-gray-900 text-center mb-3">Browse Use Cases by Function</h2>
            <p class="text-gray-500 text-center mb-12 max-w-xl mx-auto">Each use case comes with a ready-made workflow you can deploy, customize, or use as a starting point.</p>

            <div
                x-data="{ shown: false }"
                x-intersect.once="shown = true"
                class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-5"
            >
                @foreach (config('use_cases') as $slug => $uc)
                    <a
                        href="{{ route('use-cases.show', $slug) }}"
                        class="group flex flex-col rounded-2xl border border-gray-200 border-t-2 {{ $uc['accent'] }} bg-white p-6 shadow-sm hover:shadow-lg transition-all duration-300 ease-out"
                        :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                        :style="'transition-delay: {{ $loop->index * 60 }}ms'"
                    >
                        <div class="flex items-center gap-3 mb-4">
                            <span class="inline-flex items-center justify-center w-10 h-10 rounded-xl {{ $uc['icon_bg'] }} flex-shrink-0">
                                <i class="fa-solid fa-wand-magic-sparkles text-lg {{ $uc['icon_text'] }}"></i>
                            </span>
                            <span class="text-sm font-semibold text-gray-900">{{ $uc['title_short'] }}</span>
                        </div>
                        <p class="text-xs text-gray-600 leading-relaxed flex-1 mb-4">{{ Str::limit($uc['subheading'], 110) }}</p>

                        {{-- Mini step strip --}}
                        <div class="flex items-center gap-1 flex-wrap">
                            @foreach (array_slice($uc['workflow_steps'], 0, 4) as $step)
                                @php
                                $dotColor = match($step['color']) {
                                    'blue'    => 'bg-blue-400',
                                    'primary' => 'bg-primary-500',
                                    'violet'  => 'bg-violet-500',
                                    'amber'   => 'bg-amber-400',
                                    'green'   => 'bg-green-400',
                                    default   => 'bg-gray-400',
                                };
                                @endphp
                                <span class="w-2 h-2 rounded-full {{ $dotColor }} opacity-75"></span>
                                @if (!$loop->last) <div class="w-3 h-px bg-gray-200"></div> @endif
                            @endforeach
                            @if (count($uc['workflow_steps']) > 4)
                                <span class="text-gray-300 text-xs">+{{ count($uc['workflow_steps']) - 4 }}</span>
                            @endif
                        </div>

                        <div class="mt-4 flex items-center gap-1 text-xs font-medium {{ $uc['icon_text'] }} group-hover:gap-2 transition-all">
                            Explore workflow
                            <i class="fa-solid fa-arrow-right text-xs"></i>
                        </div>
                    </a>
                @endforeach
            </div>
        </div>
    </section>

    {{-- Why FleetQ section --}}
    <section class="py-16 bg-gray-50">
        <div class="mx-auto max-w-7xl px-6 lg:px-8">
            <div class="mx-auto max-w-2xl text-center mb-12">
                <h2 class="text-2xl font-bold text-gray-900 mb-3">One Platform, Any Workflow</h2>
                <p class="text-gray-500 text-base">FleetQ gives you the building blocks to automate any repeatable business process with AI agents — without writing infrastructure code.</p>
            </div>
            <div
                x-data="{ shown: false }"
                x-intersect.once="shown = true"
                class="grid grid-cols-1 md:grid-cols-3 gap-8"
            >
                @php
                $pillars = [
                    ['icon' => 'fa-solid fa-bolt', 'title' => 'Trigger from anywhere', 'desc' => 'Start workflows from webhooks, email, RSS, Telegram, scheduled jobs, or manual API calls. Any signal can kick off an agent crew.'],
                    ['icon' => 'fa-solid fa-wand-magic-sparkles', 'title' => 'Specialised agents, coordinated', 'desc' => 'Assign unique roles, tools, and skills to each agent. Crews collaborate sequentially or in parallel to solve complex, multi-step tasks.'],
                    ['icon' => 'fa-solid fa-user-circle', 'title' => 'Human-in-the-loop, always optional', 'desc' => 'Add approval gates at any workflow node. Route sensitive decisions to a human reviewer before the crew continues.'],
                ];
                @endphp
                @foreach ($pillars as $i => $p)
                    <div
                        class="flex flex-col gap-4 transition duration-500 ease-out"
                        :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                        :style="'transition-delay: {{ $i * 120 }}ms'"
                    >
                        <div class="flex items-center justify-center w-12 h-12 rounded-xl bg-primary-50 border border-primary-100">
                            <i class="{{ $p['icon'] }} text-xl text-primary-600"></i>
                        </div>
                        <h3 class="text-base font-semibold text-gray-900">{{ $p['title'] }}</h3>
                        <p class="text-sm text-gray-600 leading-relaxed">{{ $p['desc'] }}</p>
                    </div>
                @endforeach
            </div>
        </div>
    </section>

    {{-- CTA --}}
    <x-landing.cta
        heading="Ready to ship your first automated workflow?"
        subtext="Deploy in minutes on your own server. MIT licensed. No vendor lock-in."
        ctaLabel="Get started free"
        ctaHref="{{ route('register') }}"
        secondaryLabel="Read the docs"
        secondaryHref="https://docs.fleetq.io"
    />

    <x-landing.footer />
</x-layouts.public>
