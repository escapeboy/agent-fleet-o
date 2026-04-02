@props([
    'headline'    => null,
    'subheadline' => 'Create AI agents with specific roles and goals. Connect them into crews and workflows. Deploy with approval gates and budget controls baked in.',
    'ctaLabel'    => 'Get Started Free',
    'ctaHref'     => null,
    'badge'       => null,
])

<section id="hero" class="relative overflow-hidden bg-gradient-to-br from-white via-primary-50/30 to-white">
    {{-- Ambient decorative blobs --}}
    <div class="absolute -left-40 -top-40 h-80 w-80 animate-pulse rounded-full bg-primary-100/40 blur-3xl [animation-duration:6s]" aria-hidden="true"></div>
    <div class="absolute -bottom-20 -right-20 h-60 w-60 animate-pulse rounded-full bg-violet-100/30 blur-3xl [animation-duration:8s]" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-7xl px-6 py-16 sm:py-24 lg:flex lg:items-center lg:gap-x-16 lg:px-8 lg:py-32">
        {{-- Text column --}}
        <div class="mx-auto max-w-2xl lg:mx-0 lg:max-w-xl lg:flex-shrink-0"
             x-data="{ shown: false }"
             x-init="setTimeout(() => shown = true, 100)">

            {{-- Badge --}}
            <div :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                 class="transition duration-500 ease-out">
                {!! $badge ?? '<span class="inline-flex items-center gap-1.5 rounded-full border border-primary-200 bg-primary-50 px-3 py-1 text-xs font-medium text-primary-700"><i class="fa-brands fa-github text-xs"></i>Open Source &mdash; MIT License</span>' !!}
            </div>

            <h1 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                class="mt-6 text-4xl font-extrabold tracking-tight text-gray-900 transition duration-600 ease-out sm:text-5xl lg:text-6xl"
                style="transition-delay: 150ms">
                {!! $headline ?? 'Ship AI Agents From <span class="bg-gradient-to-r from-primary-600 to-violet-600 bg-clip-text text-transparent">Idea to Production</span>' !!}
            </h1>
            <p :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
               class="mt-6 text-lg leading-7 text-gray-600 transition duration-600 ease-out"
               style="transition-delay: 300ms">
                {{ $subheadline }}
            </p>
            <div :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-4'"
                 class="mt-10 flex items-center gap-x-6 transition duration-600 ease-out"
                 style="transition-delay: 450ms">
                <a href="{{ $ctaHref ?? route('register') }}"
                   class="rounded-lg bg-primary-600 px-6 py-3.5 text-base font-semibold text-white shadow-md transition hover:bg-primary-700 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                    {{ $ctaLabel }}
                </a>
                <a href="#how-it-works" class="group rounded text-sm font-semibold leading-6 text-gray-700 transition hover:text-gray-900 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">
                    See How It Works <span aria-hidden="true" class="inline-block transition-transform group-hover:translate-x-1">&rarr;</span>
                </a>
                <a href="https://github.com/escapeboy/agent-fleet-o" class="hidden items-center gap-1.5 rounded text-sm font-medium text-gray-500 transition hover:text-gray-700 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500 sm:inline-flex" rel="noopener noreferrer" target="_blank">
                    <i class="fa-brands fa-github text-base"></i>
                    Star on GitHub
                </a>
            </div>
        </div>

        {{-- Visual column --}}
        <div class="mt-16 sm:mt-24 lg:mt-0 lg:flex-shrink-0 lg:flex-grow"
             x-data="{ shown: false }"
             x-init="setTimeout(() => shown = true, 600)">
            <div class="relative mx-auto w-full max-w-2xl"
                 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
                 class="transition duration-700 ease-out">
                {{-- Decorative gradient blob --}}
                <div class="absolute -inset-4 rounded-2xl bg-gradient-to-tr from-primary-100 via-violet-50 to-transparent opacity-60 blur-2xl"></div>
                {{-- Dashboard preview placeholder --}}
                <div class="relative overflow-hidden rounded-xl border border-gray-200 bg-gray-50 shadow-2xl ring-1 ring-gray-900/5">
                    <div class="border-b border-gray-200 bg-white px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full bg-red-400"></div>
                            <div class="h-3 w-3 rounded-full bg-yellow-400"></div>
                            <div class="h-3 w-3 rounded-full bg-green-400"></div>
                            <div class="ml-4 flex items-center gap-1.5 rounded bg-gray-100 px-2.5 py-1">
                                <x-logo-icon class="h-3 w-3 text-gray-400" />
                                <span class="text-[10px] font-medium text-gray-400">FleetQ Dashboard</span>
                            </div>
                        </div>
                    </div>
                    <div class="p-6">
                        {{-- KPI cards --}}
                        <div class="grid grid-cols-3 gap-4">
                            <div class="rounded-lg border border-gray-200 bg-white p-4">
                                <div class="text-[10px] font-medium text-gray-400">Active Agents</div>
                                <div class="mt-1 text-lg font-bold text-gray-900">12</div>
                                <div class="mt-1 flex items-center gap-1">
                                    <span class="text-[9px] font-medium text-green-600">+3 today</span>
                                </div>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-white p-4">
                                <div class="text-[10px] font-medium text-gray-400">Experiments</div>
                                <div class="mt-1 text-lg font-bold text-gray-900">47</div>
                                <div class="mt-1 flex items-center gap-1">
                                    <span class="text-[9px] font-medium text-primary-600">8 running</span>
                                </div>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-white p-4">
                                <div class="text-[10px] font-medium text-gray-400">Budget Used</div>
                                <div class="mt-1 text-lg font-bold text-gray-900">62%</div>
                                <div class="mt-1 h-1.5 w-full rounded-full bg-gray-100">
                                    <div class="h-1.5 w-3/5 rounded-full bg-primary-500"></div>
                                </div>
                            </div>
                        </div>
                        {{-- Agent list --}}
                        <div class="mt-4 space-y-3">
                            <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-green-100">
                                    <div class="h-2 w-2 rounded-full bg-green-500"></div>
                                </div>
                                <div class="flex-1">
                                    <div class="text-[11px] font-medium text-gray-700">Content Researcher</div>
                                    <div class="mt-0.5 text-[9px] text-gray-400">Claude Sonnet &middot; 2m ago</div>
                                </div>
                                <span class="rounded-full bg-green-100 px-2 py-0.5 text-[9px] font-medium text-green-700">Running</span>
                            </div>
                            <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-yellow-100">
                                    <div class="h-2 w-2 rounded-full bg-yellow-500"></div>
                                </div>
                                <div class="flex-1">
                                    <div class="text-[11px] font-medium text-gray-700">Code Reviewer</div>
                                    <div class="mt-0.5 text-[9px] text-gray-400">GPT-4o &middot; Awaiting approval</div>
                                </div>
                                <span class="rounded-full bg-yellow-100 px-2 py-0.5 text-[9px] font-medium text-yellow-700">Pending</span>
                            </div>
                            <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-3">
                                <div class="flex h-8 w-8 items-center justify-center rounded-full bg-primary-100">
                                    <div class="h-2 w-2 rounded-full bg-primary-500"></div>
                                </div>
                                <div class="flex-1">
                                    <div class="text-[11px] font-medium text-gray-700">Data Analyst</div>
                                    <div class="mt-0.5 text-[9px] text-gray-400">Gemini Pro &middot; Completed</div>
                                </div>
                                <span class="rounded-full bg-primary-100 px-2 py-0.5 text-[9px] font-medium text-primary-700">Done</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
