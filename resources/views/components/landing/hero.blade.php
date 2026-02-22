<section class="relative overflow-hidden bg-gradient-to-br from-white via-primary-50/30 to-white">
    {{-- Ambient decorative blobs --}}
    <div class="absolute -left-40 -top-40 h-80 w-80 rounded-full bg-primary-100/40 blur-3xl" aria-hidden="true"></div>
    <div class="absolute -bottom-20 -right-20 h-60 w-60 rounded-full bg-primary-100/30 blur-3xl" aria-hidden="true"></div>

    <div class="relative mx-auto max-w-7xl px-6 py-16 sm:py-24 lg:flex lg:items-center lg:gap-x-16 lg:px-8 lg:py-32">
        {{-- Text column --}}
        <div class="mx-auto max-w-2xl lg:mx-0 lg:max-w-xl lg:flex-shrink-0">
            {{ $badge ?? '' }}

            <h1 class="mt-6 text-4xl font-extrabold tracking-tight text-gray-900 sm:text-5xl lg:text-6xl">
                {{ $headline ?? 'Mission Control for Your AI Agents' }}
            </h1>
            <p class="mt-6 text-lg leading-8 text-gray-600">
                {{ $subheadline ?? 'Design multi-agent crews, build visual workflows, and deploy experiments — all with human-in-the-loop approval and built-in cost controls.' }}
            </p>
            <div class="mt-10 flex items-center gap-x-6">
                <a href="{{ route('register') }}"
                   class="rounded-lg bg-primary-600 px-6 py-3.5 text-base font-semibold text-white shadow-md transition hover:bg-primary-700 hover:shadow-lg focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-600">
                    {{ $ctaLabel ?? 'Get Started Free' }}
                </a>
                <a href="#how-it-works" class="group text-sm font-semibold leading-6 text-gray-700 transition hover:text-gray-900">
                    See How It Works <span aria-hidden="true" class="inline-block transition-transform group-hover:translate-x-1">&rarr;</span>
                </a>
            </div>
        </div>

        {{-- Visual column --}}
        <div class="mt-16 sm:mt-24 lg:mt-0 lg:flex-shrink-0 lg:flex-grow">
            <div class="relative mx-auto w-full max-w-2xl">
                {{-- Decorative gradient blob --}}
                <div class="absolute -inset-4 rounded-2xl bg-gradient-to-tr from-primary-100 via-primary-50 to-transparent opacity-60 blur-2xl"></div>
                {{-- Dashboard preview placeholder --}}
                <div class="relative overflow-hidden rounded-xl border border-gray-200 bg-gray-50 shadow-2xl ring-1 ring-gray-900/5">
                    <div class="border-b border-gray-200 bg-white px-4 py-3">
                        <div class="flex items-center gap-2">
                            <div class="h-3 w-3 rounded-full bg-red-400"></div>
                            <div class="h-3 w-3 rounded-full bg-yellow-400"></div>
                            <div class="h-3 w-3 rounded-full bg-green-400"></div>
                            <div class="ml-4 h-4 w-48 rounded bg-gray-100"></div>
                        </div>
                    </div>
                    <div class="p-6">
                        {{-- Simulated dashboard UI --}}
                        <div class="grid grid-cols-3 gap-4">
                            <div class="rounded-lg border border-gray-200 bg-white p-4">
                                <div class="h-3 w-12 rounded bg-gray-200"></div>
                                <div class="mt-2 h-6 w-16 rounded bg-primary-100"></div>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-white p-4">
                                <div class="h-3 w-16 rounded bg-gray-200"></div>
                                <div class="mt-2 h-6 w-12 rounded bg-green-100"></div>
                            </div>
                            <div class="rounded-lg border border-gray-200 bg-white p-4">
                                <div class="h-3 w-10 rounded bg-gray-200"></div>
                                <div class="mt-2 h-6 w-14 rounded bg-blue-100"></div>
                            </div>
                        </div>
                        <div class="mt-4 space-y-3">
                            <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-3">
                                <div class="h-8 w-8 rounded-full bg-primary-100"></div>
                                <div class="flex-1">
                                    <div class="h-3 w-32 rounded bg-gray-200"></div>
                                    <div class="mt-1.5 h-2.5 w-48 rounded bg-gray-100"></div>
                                </div>
                                <div class="h-6 w-16 rounded-full bg-green-100"></div>
                            </div>
                            <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-3">
                                <div class="h-8 w-8 rounded-full bg-yellow-100"></div>
                                <div class="flex-1">
                                    <div class="h-3 w-40 rounded bg-gray-200"></div>
                                    <div class="mt-1.5 h-2.5 w-36 rounded bg-gray-100"></div>
                                </div>
                                <div class="h-6 w-20 rounded-full bg-yellow-100"></div>
                            </div>
                            <div class="flex items-center gap-3 rounded-lg border border-gray-200 bg-white p-3">
                                <div class="h-8 w-8 rounded-full bg-blue-100"></div>
                                <div class="flex-1">
                                    <div class="h-3 w-28 rounded bg-gray-200"></div>
                                    <div class="mt-1.5 h-2.5 w-44 rounded bg-gray-100"></div>
                                </div>
                                <div class="h-6 w-16 rounded-full bg-primary-100"></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
