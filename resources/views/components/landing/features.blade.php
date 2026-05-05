<section id="features" class="bg-white py-20 sm:py-28">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <div class="mx-auto max-w-2xl text-center">
            <h2 class="text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
                Build Agents. Control Costs. Scale Confidently.
            </h2>
            <p class="mt-4 text-lg text-gray-600">
                From a single agent to a coordinated fleet — design, govern, and scale your AI workflows.
            </p>
        </div>

        <div class="mx-auto mt-16 grid max-w-5xl grid-cols-1 gap-8 lg:grid-cols-3">
            {{-- Build --}}
            <div x-data="{ shown: false }"
                 x-intersect.once="shown = true"
                 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
                 class="rounded-2xl border border-gray-200 border-t-2 border-t-primary-400 bg-white p-8 shadow-sm transition duration-600 ease-out hover:shadow-md hover:border-gray-300">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-primary-50">
                    <i class="fa-solid fa-rocket text-2xl text-primary-600"></i>
                </div>
                <h3 class="mt-5 text-xl font-semibold text-gray-900">Build</h3>
                <p class="mt-3 text-[0.9375rem] leading-relaxed text-gray-600">
                    Create AI agents with defined roles and goals. Equip them with skills, connect external tools, and choose your preferred LLM provider.
                </p>
                <ul class="mt-5 space-y-2.5 text-sm text-gray-600">
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-primary-500"></i>
                        Multi-agent crews & coordination
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-primary-500"></i>
                        Visual workflow builder with 19 node types
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-primary-500"></i>
                        Skill & tool marketplace
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-primary-500"></i>
                        350+ MCP tools for AI agent access
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-primary-500"></i>
                        Telegram integration & signal connectors
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-primary-500"></i>
                        Browser automation via Puppeteer & Playwright MCP
                    </li>
                </ul>
            </div>

            {{-- Control --}}
            <div x-data="{ shown: false }"
                 x-intersect.once="shown = true"
                 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
                 class="rounded-2xl border border-gray-200 border-t-2 border-t-green-400 bg-white p-8 shadow-sm transition duration-600 ease-out hover:shadow-md hover:border-gray-300"
                 style="transition-delay: 150ms">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-green-50">
                    <i class="fa-solid fa-shield-halved text-2xl text-green-600"></i>
                </div>
                <h3 class="mt-5 text-xl font-semibold text-gray-900">Control</h3>
                <p class="mt-3 text-[0.9375rem] leading-relaxed text-gray-600">
                    Keep humans in the loop with approval gates, budget enforcement, and a full audit trail for every action.
                </p>
                <ul class="mt-5 space-y-2.5 text-sm text-gray-600">
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-green-500"></i>
                        Human-in-the-loop approval gates
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-green-500"></i>
                        Budget caps & real-time cost tracking
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-green-500"></i>
                        AI Safety Guardrails (PII, toxicity, budget)
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-green-500"></i>
                        Full audit trail with decision reasoning
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-green-500"></i>
                        Agent risk profiles & auto-disable
                    </li>
                </ul>
            </div>

            {{-- Scale --}}
            <div x-data="{ shown: false }"
                 x-intersect.once="shown = true"
                 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
                 class="rounded-2xl border border-gray-200 border-t-2 border-t-purple-400 bg-white p-8 shadow-sm transition duration-600 ease-out hover:shadow-md hover:border-gray-300"
                 style="transition-delay: 300ms">
                <div class="flex h-14 w-14 items-center justify-center rounded-2xl bg-purple-50">
                    <i class="fa-solid fa-chart-line text-2xl text-purple-600"></i>
                </div>
                <h3 class="mt-5 text-xl font-semibold text-gray-900">Scale</h3>
                <p class="mt-3 text-[0.9375rem] leading-relaxed text-gray-600">
                    Go from one agent to an entire fleet. Run structured experiments, iterate on results, and track every metric.
                </p>
                <ul class="mt-5 space-y-2.5 text-sm text-gray-600">
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-purple-500"></i>
                        20-state experiment pipeline with auto-retry
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-purple-500"></i>
                        Parallel and sequential workflow execution
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-purple-500"></i>
                        AI spend forecasting & semantic caching
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-purple-500"></i>
                        Automatic failover across 10+ AI providers
                    </li>
                    <li class="flex items-start gap-2">
                        <i class="fa-solid fa-check mt-0.5 text-base text-purple-500"></i>
                        Event-driven trigger rules & scheduling
                    </li>
                </ul>
            </div>
        </div>

        {{-- Security highlight row --}}
        <div class="mx-auto mt-8 max-w-5xl">
            <div x-data="{ shown: false }"
                 x-intersect.once="shown = true"
                 :class="shown ? 'opacity-100 translate-y-0' : 'opacity-0 translate-y-8'"
                 class="rounded-2xl border border-gray-200 border-t-2 border-t-indigo-400 bg-white p-8 shadow-sm transition duration-600 ease-out hover:shadow-md hover:border-gray-300"
                 style="transition-delay: 450ms">
                <div class="flex flex-col lg:flex-row lg:items-start lg:gap-10">
                    <div class="flex h-14 w-14 flex-shrink-0 items-center justify-center rounded-2xl bg-indigo-50">
                        <i class="fa-solid fa-lock text-2xl text-indigo-600"></i>
                    </div>
                    <div class="mt-5 lg:mt-0 flex-1">
                        <h3 class="text-xl font-semibold text-gray-900">Enterprise-Grade Credential Security</h3>
                        <p class="mt-3 text-[0.9375rem] leading-relaxed text-gray-600">
                            Your API keys and secrets are encrypted with dedicated per-team keys using XSalsa20-Poly1305. Pro and Enterprise teams can connect their own AWS KMS, GCP Cloud KMS, or Azure Key Vault — you hold the keys, not us.
                        </p>
                        <ul class="mt-5 grid grid-cols-1 gap-2.5 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
                            <li class="flex items-start gap-2">
                                <i class="fa-solid fa-check mt-0.5 text-base text-indigo-500"></i>
                                Per-team XSalsa20-Poly1305 encryption
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fa-solid fa-check mt-0.5 text-base text-indigo-500"></i>
                                AWS KMS, GCP Cloud KMS, Azure Key Vault
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fa-solid fa-check mt-0.5 text-base text-indigo-500"></i>
                                Revoke KMS → instantly blocks decryption
                            </li>
                            <li class="flex items-start gap-2">
                                <i class="fa-solid fa-check mt-0.5 text-base text-indigo-500"></i>
                                Credential access audit log on every decryption
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
