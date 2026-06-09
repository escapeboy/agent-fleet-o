<x-layouts.docs
    title="Test Suites"
    description="Regression test suites for agent outputs — define assertion rules and a quality threshold, run them against experiments, and gate releases on automated evaluation."
    page="test-suites"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Test Suites</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Test Suite</strong> is a reusable regression check for a project's agent outputs. It bundles a
        set of assertion rules and a quality threshold, then runs them against experiment results to produce a
        pass/fail <strong>Test Run</strong> with a score. Use suites to catch quality regressions before a
        change ships — the testing equivalent of unit tests for your agents.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A project generates weekly market summaries. The team attaches a test
        suite with a 0.85 quality threshold and assertion rules ("must cite at least two sources", "no broken
        links"). Each new run is scored automatically; runs below threshold are flagged Failed and surface for
        review.</em>
    </p>

    {{-- Concepts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Core concepts</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">TestSuite</p>
            <p class="mt-1 text-sm text-gray-600">
                Team-scoped, optionally bound to a project. Carries a <code class="font-mono text-xs">test_strategy</code>,
                <code class="font-mono text-xs">assertion_rules</code>, a <code class="font-mono text-xs">quality_threshold</code>,
                and a rolling <code class="font-mono text-xs">pass_rate</code>.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">TestRun</p>
            <p class="mt-1 text-sm text-gray-600">
                A single execution of a suite against an experiment. Records a status, a numeric
                <code class="font-mono text-xs">score</code>, structured <code class="font-mono text-xs">results</code>,
                optional agent feedback, and a duration in milliseconds.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Test strategy</p>
            <p class="mt-1 text-sm text-gray-600">
                One of <code class="font-mono text-xs">full</code>, <code class="font-mono text-xs">lint_only</code>,
                <code class="font-mono text-xs">smoke</code>, or <code class="font-mono text-xs">regression</code> —
                controls how thoroughly outputs are checked.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Quality threshold</p>
            <p class="mt-1 text-sm text-gray-600">
                The minimum score a run must reach to pass. Runs below it are marked
                <code class="font-mono text-xs">failed</code> so a quality drop can block a release.
            </p>
        </div>
    </div>

    {{-- Statuses --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Run statuses</h2>
    <p class="mt-2 text-sm text-gray-600">
        A run moves <code class="rounded bg-gray-100 px-1">pending</code> →
        <code class="rounded bg-gray-100 px-1">running</code> → one of the terminal states:
        <code class="rounded bg-gray-100 px-1">passed</code>, <code class="rounded bg-gray-100 px-1">failed</code>,
        or <code class="rounded bg-gray-100 px-1">skipped</code>.
    </p>

    {{-- Actions --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">How runs are produced</h2>
    <ul class="mt-3 list-inside list-disc space-y-1.5 text-sm text-gray-600">
        <li><code class="font-mono text-xs">RunRegressionTestsAction</code> executes a suite's regression strategy against an experiment and records a TestRun.</li>
        <li><code class="font-mono text-xs">EvaluateOutputAction</code> scores an output against the suite's assertion rules and quality threshold.</li>
    </ul>

    {{-- UI --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">In the UI</h2>
    <ul class="mt-3 list-inside list-disc space-y-1.5 text-sm text-gray-600">
        <li>
            <a href="{{ route('testing.index') }}" class="text-primary-600 hover:underline">Test Suites</a>
            lists every suite with its strategy, threshold, last-run time, and pass rate.
        </li>
        <li>
            The suite detail page shows its assertion rules and the history of test runs with per-run scores
            and results.
        </li>
    </ul>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">test_suite_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List the team's test suites.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">test_suite_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Fetch a suite with its rules and recent runs.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">test_run</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Run a suite and return the resulting TestRun.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">lint_run</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Run the lint-only strategy for a fast structural check.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        Suites focus on a project's <em>regression</em> coverage. For prompt-level, LLM-as-judge scoring of
        workflow outputs against expected answers, pair this with the
        <a href="{{ route('docs.show', 'evaluation') }}" class="text-primary-600 hover:underline">Evaluation</a>
        domain.
    </x-docs.callout>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'evaluation') }}" class="text-primary-600 hover:underline">Evaluation</a> — LLM-as-judge scoring and golden datasets.</li>
        <li><a href="{{ route('docs.show', 'projects') }}" class="text-primary-600 hover:underline">Projects</a> — the container a suite is usually bound to.</li>
        <li><a href="{{ route('docs.show', 'experiments') }}" class="text-primary-600 hover:underline">Experiments</a> — the run a suite evaluates.</li>
    </ul>
</x-layouts.docs>
