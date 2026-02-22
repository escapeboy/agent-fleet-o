@props(['extraItems' => []])

@php
    $baseItems = [
        [
            'question' => 'What is Agent Fleet?',
            'answer' => 'Agent Fleet is an AI Agent Mission Control Platform that lets you create, orchestrate, and manage teams of AI agents. You can build visual workflows, run experiments, and track results — all with human-in-the-loop approval and cost controls.',
        ],
        [
            'question' => 'Is Agent Fleet open source?',
            'answer' => 'Yes. The community edition is fully open source under the MIT license. You can self-host it on your own infrastructure. A managed cloud edition is also available with additional features like team management and billing.',
        ],
        [
            'question' => 'What AI providers are supported?',
            'answer' => 'Agent Fleet supports Anthropic (Claude), OpenAI (GPT-4o), and Google (Gemini) through PrismPHP. You can configure fallback chains between providers and bring your own API keys. Local agents like Claude Code and Codex are also supported.',
        ],
        [
            'question' => 'Can I self-host Agent Fleet?',
            'answer' => 'Absolutely. Agent Fleet runs on Docker with PHP 8.4, PostgreSQL 17, and Redis 7. The install wizard (php artisan app:install) guides you through setup in minutes.',
        ],
        [
            'question' => 'How does budget control work?',
            'answer' => 'Every AI call has a credit cost based on token usage. You can set budget caps at the experiment and global level. The system automatically pauses operations when budgets are exceeded and alerts you before limits are reached.',
        ],
        [
            'question' => 'What is MCP integration?',
            'answer' => 'Agent Fleet exposes a Model Context Protocol (MCP) server with 65+ tools. This lets external AI agents (like Claude Code or Cursor) interact with your Agent Fleet instance programmatically — creating agents, running workflows, and managing resources.',
        ],
        [
            'question' => 'Is my data secure?',
            'answer' => 'Yes. All API keys are encrypted at rest using AES-256. Agent Fleet supports Bring Your Own Key (BYOK) so your LLM credentials never leave your infrastructure. The platform includes rate limiting, budget controls, full audit trails, and role-based access control.',
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
                    <button @click="active = active === {{ $i }} ? null : {{ $i }}"
                            :aria-expanded="active === {{ $i }}"
                            aria-controls="faq-answer-{{ $i }}"
                            class="flex w-full items-center justify-between text-left">
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
                         class="mt-3 text-sm leading-relaxed text-gray-600">
                        {{ $item['answer'] }}
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</section>
