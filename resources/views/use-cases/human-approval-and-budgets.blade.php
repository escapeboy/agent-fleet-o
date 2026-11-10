@php
$pageTitle = 'Human approval and budget limits for AI agents in production';

$faqs = [
    [
        'question' => 'Can I require human approval before an AI agent acts?',
        'answer'   => 'Yes. Add an approval gate to any workflow step. The run waits until a reviewer approves or rejects it, and the decision is recorded in the audit trail.',
    ],
    [
        'question' => 'What happens when an agent hits its budget limit?',
        'answer'   => 'Work stops before more money is spent. A run or project that reaches its budget cap pauses instead of continuing, so a loop cannot quietly drain your account.',
    ],
    [
        'question' => 'Can I see what my agents did and who approved it?',
        'answer'   => 'Yes. Every action, approval and cost is written to an audit trail you can review in the app or query through the FleetQ MCP server.',
    ],
    [
        'question' => 'Do I need my own LLM API keys?',
        'answer'   => 'Yes, you bring your own keys.',
    ],
];

$jsonLd = [
    [
        '@context'   => 'https://schema.org',
        '@type'      => 'FAQPage',
        'mainEntity' => collect($faqs)->map(fn ($f) => [
            '@type'          => 'Question',
            'name'           => $f['question'],
            'acceptedAnswer' => ['@type' => 'Answer', 'text' => $f['answer']],
        ])->values()->all(),
    ],
    [
        '@context'        => 'https://schema.org',
        '@type'           => 'BreadcrumbList',
        'itemListElement' => [
            ['@type' => 'ListItem', 'position' => 1, 'name' => 'Use Cases', 'item' => route('use-cases.index')],
            ['@type' => 'ListItem', 'position' => 2, 'name' => 'Human approval and budgets', 'item' => route('use-cases.human-approval-budgets')],
        ],
    ],
];
@endphp
<x-layouts.public
    title="Human approval and budget limits for AI agents in production | FleetQ"
    description="Stop runaway agent costs and unreviewed actions. Set budgets, require human approval and keep an audit trail in one place. Self-host free or use Cloud."
    keywords="human approval for ai agents, ai agent budget limits, human in the loop ai agents, ai agent cost control, ai agent audit trail"
>
    <x-slot:head>
        @foreach ($jsonLd as $schema)
            <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
        @endforeach
    </x-slot:head>

    <x-landing.nav />

    {{-- Hero --}}
    <section class="relative overflow-hidden bg-gradient-to-b from-gray-50 to-white pt-28 pb-12">
        <div class="mx-auto max-w-5xl px-6 lg:px-8">
            <nav aria-label="Breadcrumb" class="mb-6 text-sm text-gray-500">
                <a href="{{ route('use-cases.index') }}" class="hover:text-gray-700">Use Cases</a>
                <span aria-hidden="true" class="mx-2">/</span>
                <span class="text-gray-700">Human approval and budgets</span>
            </nav>
            <div class="max-w-3xl">
                <h1 class="text-3xl font-extrabold tracking-tight text-gray-900 sm:text-4xl lg:text-5xl mb-6">
                    {{ $pageTitle }}
                </h1>
                <p class="text-lg text-gray-600 leading-relaxed mb-4">
                    AI agents are easy to demo and hard to trust in production. The model is rarely the problem.
                    Agents spend money with no ceiling, take actions nobody reviewed, and leave no record of why.
                    FleetQ puts budgets, human approval and an audit trail around your agents in one place.
                </p>
            </div>
        </div>
    </section>

    {{-- Failure modes --}}
    <section class="py-12 bg-white border-t border-gray-100">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4">The three failure modes we see</h2>
            <ol class="list-decimal space-y-3 pl-5 text-base text-gray-700 leading-relaxed">
                <li><strong class="text-gray-900">Runaway costs.</strong> A retry loop or an oversized context keeps calling the model overnight, and the first sign is the invoice.</li>
                <li><strong class="text-gray-900">Unreviewed actions.</strong> An agent sends the email, merges the change or updates the record before anyone has looked at it.</li>
                <li><strong class="text-gray-900">No audit trail.</strong> When something goes wrong, nobody can say which agent did what, what it cost, or who signed it off.</li>
            </ol>
        </div>
    </section>

    {{-- How FleetQ handles it --}}
    <section class="py-12 bg-gray-50 border-t border-gray-100">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4">How FleetQ handles it</h2>
            <ul class="list-disc space-y-3 pl-5 text-base text-gray-700 leading-relaxed">
                <li><strong class="text-gray-900">Budget caps that pause work.</strong> Set a global budget and a cap per run. When a run or project reaches its limit, it pauses instead of spending more.</li>
                <li><strong class="text-gray-900">Approval gates per workflow step.</strong> Mark any step as needing a human decision. The run waits in the approvals inbox until someone approves or rejects it.</li>
                <li><strong class="text-gray-900">A full audit trail.</strong> Every action, approval and cost is recorded with the reasoning behind it, so you can answer "what happened?" in minutes.</li>
                <li><strong class="text-gray-900">Access from your own tools.</strong> Approvals and budgets are also available through the <a href="{{ route('docs.show', 'mcp-server') }}" class="font-medium text-primary-600 hover:text-primary-700">FleetQ MCP server</a>, so you can review and approve from the client you already use.</li>
            </ul>
        </div>
    </section>

    {{-- Build vs buy --}}
    <section class="py-12 bg-white border-t border-gray-100">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-4">Compared with building it yourself</h2>
            <p class="text-base text-gray-700 leading-relaxed mb-4">
                You can build these controls on top of any agent framework: a cost counter around each model call,
                a queue that holds risky actions for review, and a log table for everything else. Each piece is small.
                Keeping them consistent across every agent, workflow and integration is the hard part, and it is work
                that does not ship features.
            </p>
            <p class="text-base text-gray-700 leading-relaxed mb-4">
                FleetQ gives you those controls out of the box, and you keep your own model keys.
                If you are weighing other tools, <a href="{{ route('compare.index') }}" class="font-medium text-primary-600 hover:text-primary-700">see how FleetQ compares to LangGraph, CrewAI and n8n</a>.
            </p>
        </div>
    </section>

    {{-- Try it --}}
    <section class="bg-gradient-to-r from-primary-700 via-primary-600 to-violet-700 py-16 sm:py-20">
        <div class="mx-auto max-w-4xl px-6 text-center lg:px-8">
            <h2 class="text-3xl font-bold tracking-tight text-white">Try it</h2>
            <p class="mx-auto mt-4 max-w-xl text-lg text-primary-100">Self-host the AGPL-3.0 Community Edition for free, or start on FleetQ Cloud.</p>
            <div class="mt-8 flex flex-col sm:flex-row items-center justify-center gap-4">
                <a href="{{ route('register') }}"
                   data-analytics-event="cta_click" data-analytics-label="try_cloud"
                   class="rounded-lg bg-white px-6 py-3 text-sm font-semibold text-primary-600 shadow-sm transition hover:bg-primary-50">
                    Start free on Cloud
                </a>
                <a href="https://github.com/escapeboy/agent-fleet-o"
                   data-analytics-event="cta_click" data-analytics-label="self_host_github"
                   target="_blank" rel="noopener noreferrer"
                   class="rounded-lg border border-white/60 px-6 py-3 text-sm font-semibold text-white transition hover:bg-white/10">
                    Self-host from GitHub
                </a>
            </div>
        </div>
    </section>

    {{-- FAQ --}}
    <section class="py-12 bg-gray-50 border-t border-gray-100">
        <div class="mx-auto max-w-3xl px-6 lg:px-8">
            <h2 class="text-xl font-bold text-gray-900 mb-6">FAQ</h2>
            <dl class="space-y-6">
                @foreach ($faqs as $faq)
                    <div>
                        <dt class="text-base font-semibold text-gray-900">{{ $faq['question'] }}</dt>
                        <dd class="mt-2 text-sm text-gray-700 leading-relaxed">{{ $faq['answer'] }}</dd>
                    </div>
                @endforeach
            </dl>
        </div>
    </section>

    <x-landing.footer />
</x-layouts.public>
