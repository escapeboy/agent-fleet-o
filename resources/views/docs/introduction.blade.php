<x-layouts.docs
    title="What is FleetQ?"
    description="Learn what FleetQ is — an AI agent mission control platform for building, deploying, and managing autonomous AI workflows."
    page="introduction"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">What is FleetQ?</h1>
    <p class="mt-4 text-lg text-gray-600">
        FleetQ is an <strong>AI agent mission control platform</strong>. You define goals; FleetQ deploys agents,
        monitors them, enforces budgets, and delivers results — automatically.
    </p>
    <p class="mt-3 text-gray-600">
        Whether you're automating competitive research, lead qualification, content generation, or customer
        outreach — FleetQ gives you a production-grade operating layer for AI agents without writing infrastructure code.
    </p>

    {{-- What you can do --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">What you can do with FleetQ</h2>
    <div class="mt-4 grid gap-4 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-100 text-primary-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" />
                </svg>
            </div>
            <h3 class="mt-3 font-semibold text-gray-900">Run AI Pipelines</h3>
            <p class="mt-1 text-sm text-gray-600">
                A 20-state experiment engine orchestrates multi-step AI workflows with automatic checkpointing, retries, and approval gates.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-100 text-primary-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z" />
                </svg>
            </div>
            <h3 class="mt-3 font-semibold text-gray-900">Coordinate Agent Teams</h3>
            <p class="mt-1 text-sm text-gray-600">
                Crews let multiple agents collaborate — a coordinator assigns tasks, specialists execute them, reviewers validate the output.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-100 text-primary-600">
                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
            </div>
            <h3 class="mt-3 font-semibold text-gray-900">Schedule Recurring Work</h3>
            <p class="mt-1 text-sm text-gray-600">
                Projects run AI workflows on a schedule — hourly, daily, weekly, or cron. Every run is tracked, budgeted, and audited.
            </p>
        </div>
    </div>

    {{-- Key concepts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Key concepts</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Concept</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it is</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Experiment</td>
                    <td class="py-3 pr-4 text-gray-600">A single AI pipeline run — scored, planned, built, executed, and evaluated through up to 20 states.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Agent</td>
                    <td class="py-3 pr-4 text-gray-600">An AI worker with a role, goal, and backstory. Agents power every experiment, crew task, and workflow node.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Skill</td>
                    <td class="py-3 pr-4 text-gray-600">A reusable, versioned capability — an LLM prompt, connector call, rule engine, or guardrail.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Crew</td>
                    <td class="py-3 pr-4 text-gray-600">A team of agents working in parallel or sequence, coordinated by an orchestrator.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Workflow</td>
                    <td class="py-3 pr-4 text-gray-600">A visual DAG (directed acyclic graph) of nodes: agents, conditions, human tasks, loops, and more.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Signal</td>
                    <td class="py-3 pr-4 text-gray-600">Any inbound event — a webhook payload, RSS item, CRM lead, or manual entry.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Project</td>
                    <td class="py-3 pr-4 text-gray-600">A container for scheduled, recurring AI work. Each trigger creates a ProjectRun with full history.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- CTA --}}
    <div class="mt-10 flex items-center justify-between rounded-xl border border-primary-200 bg-primary-50 px-6 py-5">
        <div>
            <p class="font-semibold text-primary-900">Ready to see it in action?</p>
            <p class="mt-0.5 text-sm text-primary-700">Follow the quick start guide and run your first AI workflow in 5 minutes.</p>
        </div>
        <a href="{{ route('docs.show', 'getting-started') }}"
           class="shrink-0 rounded-lg bg-primary-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700">
            Get started →
        </a>
    </div>
</x-layouts.docs>
