@props(['extraItems' => []])

@php
    $baseItems = [
        [
            'question' => 'What is FleetQ?',
            'answer' => 'FleetQ is an AI Agent Mission Control Platform. You create AI agents with specific roles and goals, assemble them into multi-agent crews, and orchestrate their work through visual workflows. Every run includes human-in-the-loop approval gates and budget controls, so you stay in charge.',
        ],
        [
            'question' => 'Is FleetQ open source?',
            'answer' => 'Yes. FleetQ is fully open source under the MIT license. Self-host it on your own infrastructure with complete control over your data, models, and configuration.',
        ],
        [
            'question' => 'What AI providers are supported?',
            'answer' => 'FleetQ supports cloud providers — Anthropic (Claude), OpenAI (GPT-4o), Google (Gemini), Groq, and OpenRouter — with automatic failover. You bring your own API keys. Seven local CLI agents (Claude Code, Codex, Gemini CLI, Kiro, Aider, Amp, OpenCode) are also supported at zero platform cost.',
        ],
        [
            'question' => 'Can I self-host FleetQ?',
            'answer' => 'Yes. FleetQ ships as a Docker stack with PHP 8.4, PostgreSQL 17, and Redis 7. Run the install wizard and it walks you through database setup, admin account creation, AI provider keys, and default agent configuration in under five minutes.',
        ],
        [
            'question' => 'How does budget control work?',
            'answer' => 'Every LLM call has a credit cost calculated from token usage. You set budget caps at the global and per-experiment level. The platform alerts you at 80% usage and automatically pauses operations when the budget is exhausted — no surprise bills.',
        ],
        [
            'question' => 'What is MCP integration?',
            'answer' => 'FleetQ includes a Model Context Protocol (MCP) server with 140+ tools across 24 domains. External AI agents — Claude Code, Codex, Gemini CLI, Kiro, Amp, Cursor, and more — can manage your FleetQ instance directly: creating agents, triggering workflows, checking budgets, and browsing the marketplace.',
        ],
        [
            'question' => 'Is my data secure?',
            'answer' => 'All API keys are encrypted at rest with AES-256. With Bring Your Own Key (BYOK), your LLM credentials never leave your infrastructure. The platform enforces rate limiting, budget caps, target blacklists, a complete audit trail, and role-based access control with four permission levels.',
        ],
    ];
    $items = array_merge($baseItems, $extraItems);
    $mid = (int) ceil(count($items) / 2);
    $leftItems = array_slice($items, 0, $mid);
    $rightItems = array_slice($items, $mid);
@endphp

<section id="faq" class="bg-white py-20 sm:py-28">
    <div class="mx-auto max-w-7xl px-6 lg:px-8">
        <h2 class="text-center text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
            Frequently Asked Questions
        </h2>

        <div class="mt-12 grid grid-cols-1 gap-x-16 lg:grid-cols-2">
            {{-- Left column --}}
            <div x-data="{ active: null }" class="divide-y divide-gray-200">
                @foreach($leftItems as $i => $item)
                    <div class="py-5">
                        <button id="faq-l-q{{ $i }}"
                                @click="active = active === {{ $i }} ? null : {{ $i }}"
                                :aria-expanded="active === {{ $i }}"
                                aria-controls="faq-l-a{{ $i }}"
                                class="flex w-full items-center justify-between rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">
                            <span class="text-base font-medium text-gray-900">{{ $item['question'] }}</span>
                            <svg :class="active === {{ $i }} && 'rotate-180'"
                                 class="ml-4 h-5 w-5 flex-shrink-0 text-gray-400 transition-transform duration-200"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="faq-l-a{{ $i }}"
                             x-show="active === {{ $i }}"
                             x-cloak
                             x-collapse
                             role="region"
                             aria-labelledby="faq-l-q{{ $i }}"
                             class="mt-3 text-sm leading-relaxed text-gray-600">
                            {{ $item['answer'] }}
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Right column --}}
            <div x-data="{ active: null }" class="divide-y divide-gray-200 border-t border-gray-200 lg:border-t-0">
                @foreach($rightItems as $i => $item)
                    <div class="py-5">
                        <button id="faq-r-q{{ $i }}"
                                @click="active = active === {{ $i }} ? null : {{ $i }}"
                                :aria-expanded="active === {{ $i }}"
                                aria-controls="faq-r-a{{ $i }}"
                                class="flex w-full items-center justify-between rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">
                            <span class="text-base font-medium text-gray-900">{{ $item['question'] }}</span>
                            <svg :class="active === {{ $i }} && 'rotate-180'"
                                 class="ml-4 h-5 w-5 flex-shrink-0 text-gray-400 transition-transform duration-200"
                                 fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                            </svg>
                        </button>
                        <div id="faq-r-a{{ $i }}"
                             x-show="active === {{ $i }}"
                             x-cloak
                             x-collapse
                             role="region"
                             aria-labelledby="faq-r-q{{ $i }}"
                             class="mt-3 text-sm leading-relaxed text-gray-600">
                            {{ $item['answer'] }}
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    </div>
</section>
