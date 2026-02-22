<section id="how-it-works" class="bg-gray-50 py-20 sm:py-28">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">How It Works</h2>
            <p class="mt-4 text-lg text-gray-600">Three steps from idea to production AI automation.</p>
        </div>

        <div class="mx-auto mt-16 grid max-w-5xl grid-cols-1 gap-12 md:grid-cols-3">
            {{-- Step 1 --}}
            <div x-data="{ shown: false }"
                 x-intersect.once="shown = true"
                 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
                 class="relative text-center transition duration-600 ease-out">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-600 text-xl font-bold text-white">1</div>
                <h3 class="mt-6 text-lg font-semibold text-gray-900">Design</h3>
                <p class="mt-3 text-sm leading-relaxed text-gray-600">
                    Create AI agents with specific roles and goals. Attach skills, tools, and credentials. Choose your LLM providers.
                </p>
                {{-- Connector line (hidden on mobile) --}}
                <div class="absolute right-0 top-7 hidden h-0.5 w-12 translate-x-full bg-gray-300 md:block"></div>
            </div>

            {{-- Step 2 --}}
            <div x-data="{ shown: false }"
                 x-intersect.once="shown = true"
                 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
                 class="relative text-center transition duration-600 ease-out"
                 style="transition-delay: 150ms">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-600 text-xl font-bold text-white">2</div>
                <h3 class="mt-6 text-lg font-semibold text-gray-900">Build</h3>
                <p class="mt-3 text-sm leading-relaxed text-gray-600">
                    Assemble multi-agent crews or design workflows with the visual editor. Add conditional logic, human tasks, and parallel branches.
                </p>
                <div class="absolute right-0 top-7 hidden h-0.5 w-12 translate-x-full bg-gray-300 md:block"></div>
            </div>

            {{-- Step 3 --}}
            <div x-data="{ shown: false }"
                 x-intersect.once="shown = true"
                 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
                 class="text-center transition duration-600 ease-out"
                 style="transition-delay: 300ms">
                <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-600 text-xl font-bold text-white">3</div>
                <h3 class="mt-6 text-lg font-semibold text-gray-900">Deploy</h3>
                <p class="mt-3 text-sm leading-relaxed text-gray-600">
                    Run experiments with human approval gates, budget controls, and real-time metrics. Iterate on results and scale what works.
                </p>
            </div>
        </div>
    </div>
</section>
