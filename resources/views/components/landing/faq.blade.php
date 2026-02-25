@props(['extraItems' => []])

@php
    $baseItems = [
        [
            'question' => 'What is FleetQ?',
            'answer' => 'FleetQ is an AI Agent Mission Control Platform. You create AI agents with specific roles and goals, assemble them into multi-agent crews, and orchestrate their work through visual workflows. Every run includes human-in-the-loop approval gates and budget controls, so you stay in charge.',
        ],
        [
            'question' => 'Is FleetQ open source?',
            'answer' => 'Yes. The community edition is fully open source under the MIT license. You can self-host it on your own infrastructure. A managed cloud edition is also available with additional features like team management and billing.',
        ],
        [
            'question' => 'What AI providers are supported?',
            'answer' => 'FleetQ supports three cloud providers — Anthropic (Claude), OpenAI (GPT-4o), and Google (Gemini) — with automatic failover between them. You bring your own API keys, so your credentials stay on your infrastructure. Local agents like Claude Code and OpenAI Codex are also supported at zero platform cost.',
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
            'answer' => 'FleetQ includes a Model Context Protocol (MCP) server with 120+ tools across 20 domains. This means external AI agents — such as Claude Code, OpenAI Codex, or Cursor — can manage your FleetQ instance directly: creating agents, triggering workflows, checking budgets, and browsing the marketplace.',
        ],
        [
            'question' => 'Is my data secure?',
            'answer' => 'All API keys are encrypted at rest with AES-256. With Bring Your Own Key (BYOK), your LLM credentials never leave your infrastructure. The platform enforces rate limiting, budget caps, target blacklists, a complete audit trail, and role-based access control with four permission levels.',
        ],
    ];
    $items = array_merge($baseItems, $extraItems);
@endphp

<section id="faq" class="bg-white py-20 sm:py-28">
    <div class="mx-auto max-w-3xl px-6 lg:px-8">
        <h2 class="text-center text-3xl font-bold tracking-tight text-gray-900 sm:text-4xl">
            Frequently Asked Questions
        </h2>

        <div x-data="{ active: null }" class="mt-12 divide-y divide-gray-200">
            @foreach($items as $i => $item)
                <div class="py-5">
                    <button id="faq-question-{{ $i }}"
                            @click="active = active === {{ $i }} ? null : {{ $i }}"
                            :aria-expanded="active === {{ $i }}"
                            aria-controls="faq-answer-{{ $i }}"
                            class="flex w-full items-center justify-between rounded text-left focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary-500">
                        <span class="text-base font-medium text-gray-900">{{ $item['question'] }}</span>
                        <svg :class="active === {{ $i }} && 'rotate-180'"
                             class="ml-4 h-5 w-5 flex-shrink-0 text-gray-400 transition-transform duration-200"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/>
                        </svg>
                    </button>
                    <div id="faq-answer-{{ $i }}"
                         x-show="active === {{ $i }}"
                         x-cloak
                         x-collapse
                         role="region"
                         aria-labelledby="faq-question-{{ $i }}"
                         class="mt-3 text-sm leading-relaxed text-gray-600">
                        {{ $item['answer'] }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
