<x-layouts.docs
    title="Projects"
    description="FleetQ Projects run AI workflows on a schedule. Learn about one-shot vs continuous projects, scheduling, milestones, and budget controls."
    page="projects"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Projects — Scheduled & Continuous AI Work</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Project</strong> is a container for recurring or one-off AI work. Each time the schedule fires
        (or you trigger manually), FleetQ creates a <strong>ProjectRun</strong> — a timestamped execution with
        its own budget, status, and artifact history.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A SaaS startup runs a daily "Churn Risk Scanner" project that analyses
        CRM usage data, identifies accounts that haven't logged in for 14 days, drafts personalised win-back
        emails, and delivers them to the CS team Slack channel.</em>
    </p>

    {{-- Project types --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Project types</h2>
    <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-5">
            <p class="font-semibold text-gray-900">One-Shot</p>
            <p class="mt-2 text-sm text-gray-600">
                Runs once on activation. Perfect for long-running data processing tasks, one-time migrations,
                or experiments you want to track as a project with milestones.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-5">
            <p class="font-semibold text-gray-900">Continuous</p>
            <p class="mt-2 text-sm text-gray-600">
                Runs on a schedule — hourly, daily, weekly, or custom cron. Each run is independent.
                Use for monitoring, digests, lead qualification, and any repeating AI workflow.
            </p>
        </div>
    </div>

    {{-- Schedule frequencies --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Schedule frequencies</h2>
    <div class="mt-3 grid gap-2 sm:grid-cols-3">
        @foreach(['hourly', 'daily', 'weekly', 'monthly', 'cron'] as $freq)
        <div class="rounded-lg border border-gray-200 px-3 py-2 text-center font-mono text-sm text-gray-700">{{ $freq }}</div>
        @endforeach
    </div>
    <p class="mt-2 text-xs text-gray-500">
        Cron expressions support standard 5-field syntax: <code class="rounded bg-gray-100 px-1">0 8 * * 1</code> = every Monday at 08:00.
        The scheduler resolves due projects every minute — sub-minute scheduling is not supported.
    </p>

    {{-- Overlap policies --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Overlap policies</h2>
    <p class="mt-2 text-sm text-gray-600">What happens if a previous run is still active when the next schedule fires:</p>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Policy</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Behaviour</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">skip</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Skip the new run. The in-progress run finishes undisturbed.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">queue</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Queue the new run. Starts as soon as the previous run completes.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">cancel_previous</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Kill the running run and start the new one immediately.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Milestones --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Milestones</h2>
    <p class="mt-2 text-sm text-gray-600">
        Attach business milestones to a project — e.g. "Process 1,000 leads" or "Generate 50 blog posts".
        Track progress on the project detail page. Milestones generate notifications when reached.
    </p>

    {{-- Budget --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Budget per project</h2>
    <p class="mt-2 text-sm text-gray-600">
        Set a spending cap on each project. When the total spend across all runs approaches the limit:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li>At <strong>80%</strong>: notification sent to project owner</li>
        <li>At <strong>100%</strong>: new runs are blocked until budget is increased</li>
    </ul>
    <p class="mt-2 text-sm text-gray-600">
        Projects also inherit the team-level budget. If either budget is exhausted, runs are blocked.
    </p>

    {{-- API --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Trigger a run manually</h2>
    <x-docs.code lang="bash">
curl -X POST {{ url('/api/v1/projects/PROJECT_ID/trigger') }} \
  -H "Authorization: Bearer YOUR_TOKEN"</x-docs.code>
</x-layouts.docs>
