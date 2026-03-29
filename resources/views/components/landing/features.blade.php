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
                    <svg class="h-7 w-7 text-primary-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.42 15.17l-5.21-3.01a.88.88 0 010-1.52l10.32-5.96a.88.88 0 011.32.76v11.12a.88.88 0 01-1.32.76l-5.11-2.95" />
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 21v-8.25M15.75 18c0 .414-.168.75-.375.75S15 18.414 15 18s.168-.75.375-.75.375.336.375.75zm0 0h.008v.015h-.008V18zm-4.5 0c0 .414-.168.75-.375.75S10.5 18.414 10.5 18s.168-.75.375-.75.375.336.375.75zm0 0h.008v.015h-.008V18z" />
                    </svg>
                </div>
                <h3 class="mt-5 text-xl font-semibold text-gray-900">Build</h3>
                <p class="mt-3 text-[0.9375rem] leading-relaxed text-gray-600">
                    Create AI agents with defined roles and goals. Equip them with skills, connect external tools, and choose your preferred LLM provider.
                </p>
                <ul class="mt-5 space-y-2.5 text-sm text-gray-600">
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Multi-agent crews & coordination
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Visual workflow builder with 19 node types
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Skill & tool marketplace
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        350+ MCP tools for AI agent access
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Telegram integration & signal connectors
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-primary-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
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
                    <svg class="h-7 w-7 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75m-3-7.036A11.959 11.959 0 013.598 6 11.99 11.99 0 003 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285z" />
                    </svg>
                </div>
                <h3 class="mt-5 text-xl font-semibold text-gray-900">Control</h3>
                <p class="mt-3 text-[0.9375rem] leading-relaxed text-gray-600">
                    Keep humans in the loop with approval gates, budget enforcement, and a full audit trail for every action.
                </p>
                <ul class="mt-5 space-y-2.5 text-sm text-gray-600">
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Human-in-the-loop approval gates
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Budget caps & real-time cost tracking
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        AI Safety Guardrails (PII, toxicity, budget)
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Full audit trail with decision reasoning
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-green-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
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
                    <svg class="h-7 w-7 text-purple-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 3v11.25A2.25 2.25 0 006 16.5h2.25M3.75 3h-1.5m1.5 0h16.5m0 0h1.5m-1.5 0v11.25A2.25 2.25 0 0118 16.5h-2.25m-7.5 0h7.5m-7.5 0l-1 3m8.5-3l1 3m0 0l.5 1.5m-.5-1.5h-9.5m0 0l-.5 1.5M9 11.25v1.5M12 9v3.75m3-6v6" />
                    </svg>
                </div>
                <h3 class="mt-5 text-xl font-semibold text-gray-900">Scale</h3>
                <p class="mt-3 text-[0.9375rem] leading-relaxed text-gray-600">
                    Go from one agent to an entire fleet. Run structured experiments, iterate on results, and track every metric.
                </p>
                <ul class="mt-5 space-y-2.5 text-sm text-gray-600">
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        20-state experiment pipeline with auto-retry
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Parallel and sequential workflow execution
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        AI spend forecasting & semantic caching
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                        Automatic failover across 10+ AI providers
                    </li>
                    <li class="flex items-start gap-2">
                        <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-purple-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
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
                        <svg class="h-7 w-7 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </div>
                    <div class="mt-5 lg:mt-0 flex-1">
                        <h3 class="text-xl font-semibold text-gray-900">Enterprise-Grade Credential Security</h3>
                        <p class="mt-3 text-[0.9375rem] leading-relaxed text-gray-600">
                            Your API keys and secrets are encrypted with dedicated per-team keys using XSalsa20-Poly1305. Pro and Enterprise teams can connect their own AWS KMS, GCP Cloud KMS, or Azure Key Vault — you hold the keys, not us.
                        </p>
                        <ul class="mt-5 grid grid-cols-1 gap-2.5 text-sm text-gray-600 sm:grid-cols-2 lg:grid-cols-4">
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-indigo-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                                Per-team XSalsa20-Poly1305 encryption
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-indigo-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                                AWS KMS, GCP Cloud KMS, Azure Key Vault
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-indigo-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                                Revoke KMS → instantly blocks decryption
                            </li>
                            <li class="flex items-start gap-2">
                                <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-indigo-500" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd"/></svg>
                                Credential access audit log on every decryption
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
