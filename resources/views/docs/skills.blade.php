<x-layouts.docs
    title="Skills"
    description="Skills are versioned, reusable AI capabilities in FleetQ. Learn about skill types, cost estimation, guardrails, and JSON schema validation."
    page="skills"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Skills — Reusable AI Capabilities</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Skill</strong> is a versioned, reusable capability that an agent can invoke. Skills encapsulate
        prompts, connector calls, business rules, or output guardrails — keeping your agents clean and composable.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A marketing team creates a "Tone Rewriter" skill that rephrases any text
        to match their brand voice. The same skill is reused by the Blog Writer agent, the Social Post agent,
        and the Email Copywriter agent — with consistent, tested prompting.</em>
    </p>

    {{-- Skill types --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Skill types</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Type</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it does</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">llm</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Calls the language model with a prompt template. Most common type.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">connector</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Fetches or sends data to an external service (API, database, webhook).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">rule</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Evaluates deterministic business logic — no LLM call. Fast and cheap.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">hybrid</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Combines LLM + rules/connectors in a single skill invocation.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">guardrail</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Validates or blocks output that doesn't meet quality/safety criteria.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Versioning --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Versioning</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every time you save a skill, FleetQ creates a new <strong>SkillVersion</strong>. The active version is
        used by all agents referencing this skill. You can:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li>View all versions from the skill detail page</li>
        <li>Roll back to any previous version</li>
        <li>Track what changed between versions (diff view)</li>
    </ul>
    <x-docs.callout type="tip">
        Always test a new skill version on a non-production experiment before rolling it out to all agents.
        Use the <strong>Execute Skill</strong> button on the skill detail page for a quick sanity check.
    </x-docs.callout>

    {{-- Risk levels --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Risk levels</h2>
    <p class="mt-2 text-sm text-gray-600">
        Each skill has a risk level that determines whether human approval is required before execution:
    </p>
    <div class="mt-3 grid gap-3 sm:grid-cols-3">
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-center">
            <p class="font-semibold text-green-800">Low</p>
            <p class="mt-1 text-xs text-green-700">Auto-execute. No approval needed.</p>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-center">
            <p class="font-semibold text-amber-800">Medium</p>
            <p class="mt-1 text-xs text-amber-700">Configurable — can require approval.</p>
        </div>
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-center">
            <p class="font-semibold text-red-800">High</p>
            <p class="mt-1 text-xs text-red-700">Always requires human approval before execution.</p>
        </div>
    </div>

    {{-- Cost estimation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Cost estimation</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ's <code class="rounded bg-gray-100 px-1 text-xs">SkillCostCalculator</code> estimates token usage
        before a skill runs, reserves the budget with a 1.5× safety multiplier, then settles the actual cost
        after completion. You're never surprised by overspend.
    </p>

    {{-- Schema validation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">JSON schema validation</h2>
    <p class="mt-2 text-sm text-gray-600">
        Define input and output schemas for any skill. FleetQ validates data against the schema at runtime,
        rejecting malformed inputs before they reach the LLM and ensuring outputs conform to your expected structure.
    </p>

    <x-docs.code lang="json" title="Example output schema">
{
  "type": "object",
  "required": ["summary", "score"],
  "properties": {
    "summary": { "type": "string", "maxLength": 500 },
    "score":   { "type": "number", "minimum": 0, "maximum": 100 },
    "tags":    { "type": "array", "items": { "type": "string" } }
  }
}</x-docs.code>
</x-layouts.docs>
