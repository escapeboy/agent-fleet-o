<x-layouts.docs
    title="Metrics & Model Comparison"
    description="Track performance across experiments, agents, and LLM providers. Compare models by cost, latency, and quality. Monitor semantic cache efficiency and revenue attribution."
    page="metrics"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Metrics &amp; Model Comparison</h1>
    <p class="mt-4 text-gray-600">
        FleetQ tracks performance metrics across all experiments, agents, and LLM providers.
        Use metrics to compare model performance, optimize costs, and identify bottlenecks in your AI workflows.
    </p>

    {{-- What's Tracked --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">What's tracked</h2>
    <p class="mt-2 text-sm text-gray-600">Every AI run records the following dimensions:</p>

    <div class="mt-4 overflow-hidden rounded-lg border border-gray-200">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2.5 text-left font-medium text-gray-700">Metric</th>
                    <th class="px-4 py-2.5 text-left font-medium text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-800">response_time</td><td class="px-4 py-2 text-gray-600">End-to-end latency in milliseconds</td></tr>
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-800">input_tokens</td><td class="px-4 py-2 text-gray-600">Prompt tokens consumed</td></tr>
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-800">output_tokens</td><td class="px-4 py-2 text-gray-600">Completion tokens generated</td></tr>
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-800">cost_credits</td><td class="px-4 py-2 text-gray-600">Cost in credits (1 credit = $0.001)</td></tr>
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-800">success_rate</td><td class="px-4 py-2 text-gray-600">Ratio of successful completions to total attempts</td></tr>
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-800">quality_score</td><td class="px-4 py-2 text-gray-600">Evaluator-assigned score (0–100)</td></tr>
                <tr><td class="px-4 py-2 font-mono text-xs text-gray-800">throughput</td><td class="px-4 py-2 text-gray-600">Runs completed per hour per agent</td></tr>
            </tbody>
        </table>
    </div>

    {{-- Aggregation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Metric aggregation</h2>
    <p class="mt-2 text-sm text-gray-600">
        Raw metrics are aggregated on two schedules via the <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">metrics:aggregate</code> Artisan command:
    </p>
    <ul class="mt-3 space-y-1 text-sm text-gray-600 list-disc list-inside">
        <li><strong>Hourly</strong> — runs at the top of every hour</li>
        <li><strong>Daily</strong> — runs at 01:00 UTC</li>
    </ul>
    <p class="mt-3 text-sm text-gray-600">
        Aggregations are stored in the <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">metric_aggregations</code> table and power
        dashboard charts without hitting raw event rows on every page load.
    </p>

    <x-docs.code lang="bash">
# Trigger aggregation manually
php artisan metrics:aggregate --period=hourly
php artisan metrics:aggregate --period=daily</x-docs.code>

    {{-- Model Comparison --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Model comparison</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <a href="/metrics/models" class="text-primary-600 hover:underline">/metrics/models</a> page lets you compare
        LLM providers side-by-side across cost, latency, quality scores, and error rates.
        Use it to decide which model is best suited for a given task type.
    </p>

    <x-docs.callout type="tip" title="Provider selection tip">
        Run the same experiment with two different providers, then use the model comparison view to compare quality vs. cost.
        Cheaper models often match expensive ones for structured extraction tasks.
    </x-docs.callout>

    <x-docs.code lang="bash">
# Fetch model comparison data via API
curl {{ url('/api/v1/dashboard') }} \
  -H "Authorization: Bearer YOUR_TOKEN"</x-docs.code>

    {{-- Revenue Attribution --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Revenue attribution</h2>
    <p class="mt-2 text-sm text-gray-600">
        <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">AttributeRevenueAction</code> links experiment outcomes to business metrics,
        allowing you to track the ROI of AI workflows. Attach a revenue metric to any experiment
        to see which agents and models drive the most value.
    </p>

    {{-- Dashboard KPIs --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Dashboard KPIs</h2>
    <p class="mt-2 text-sm text-gray-600">
        The main dashboard surfaces key metrics at a glance:
    </p>
    <ul class="mt-3 space-y-1 text-sm text-gray-600 list-disc list-inside">
        <li>Active experiments &amp; success rate</li>
        <li>Total spend (credits) this period</li>
        <li>Agent utilization &amp; queue depth</li>
        <li>Top providers by cost and volume</li>
    </ul>
    <p class="mt-3 text-sm text-gray-600">
        The same data is available programmatically via the <code class="rounded bg-gray-100 px-1 py-0.5 text-xs">system_dashboard_kpis</code> MCP tool,
        so your agents can reason about platform health.
    </p>

    {{-- Semantic Cache --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Semantic cache performance</h2>
    <p class="mt-2 text-sm text-gray-600">
        The AI gateway caches semantically similar prompts using pgvector (cosine similarity threshold 0.92).
        Monitor cache efficiency via MCP or the API to understand how much spend is being avoided.
    </p>

    <x-docs.code lang="json" title="MCP tools">
semantic_cache_stats   // hit rate, savings, total cached entries
semantic_cache_purge   // flush cache entries (admin only)</x-docs.code>

    <x-docs.callout type="info" title="Cache is cross-team">
        The semantic cache is intentionally shared across teams (no tenant isolation) to maximise hit rates.
        No team data is exposed — only the cached model response is reused.
    </x-docs.callout>
</x-layouts.docs>
