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
                <i class="fa-solid fa-flask text-lg" aria-hidden="true"></i>
            </div>
            <h3 class="mt-3 font-semibold text-gray-900">Run AI Pipelines</h3>
            <p class="mt-1 text-sm text-gray-600">
                A 20-state experiment engine orchestrates multi-step AI workflows with automatic checkpointing, retries, and approval gates.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-100 text-primary-600">
                <i class="fa-solid fa-users text-lg" aria-hidden="true"></i>
            </div>
            <h3 class="mt-3 font-semibold text-gray-900">Coordinate Agent Teams</h3>
            <p class="mt-1 text-sm text-gray-600">
                Crews let multiple agents collaborate — a coordinator assigns tasks, specialists execute them, reviewers validate the output.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 bg-white p-5">
            <div class="flex h-10 w-10 items-center justify-center rounded-lg bg-primary-100 text-primary-600">
                <i class="fa-regular fa-calendar text-lg" aria-hidden="true"></i>
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
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Approval</td>
                    <td class="py-3 pr-4 text-gray-600">A human-in-the-loop gate. Agents pause for human review before taking irreversible actions.</td>
                </tr>
                <tr>
                    <td class="py-3 pl-4 pr-6 font-medium text-gray-900">Tool</td>
                    <td class="py-3 pr-4 text-gray-600">An external capability (MCP server, shell, browser) that extends what agents can do beyond language generation.</td>
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
