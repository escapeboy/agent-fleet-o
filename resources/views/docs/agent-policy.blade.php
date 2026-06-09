<x-layouts.docs
    title="Agent Policies"
    description="Policy-Governed Autonomy — versioned, per-agent authority boundaries that decide when an agent's proposed real-world action can auto-execute versus when it must wait for a human."
    page="agent-policy"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Agent Policies — Governed Autonomy</h1>
    <p class="mt-4 text-gray-600">
        An <strong>Agent Policy</strong> is a versioned authority boundary for an agent. It sits on top of the
        existing real-world action governance flow and decides, for each proposed action, whether the agent may
        execute it automatically or must hand it to a human. A policy can only ever <em>narrow</em> autonomy —
        the strongest verdict it hands out is "allow auto", and it only reaches that when every rule is
        satisfied and the policy explicitly opts in.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>An agent proposes to push a doc change and to run a database migration.
        The agent's policy auto-approves the low-risk doc change (its rubric score clears the auto-execute
        threshold) but holds the migration for human review — migrations are on the policy deny list.</em>
    </p>

    {{-- Relation to action proposals --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">How it relates to Action Proposals</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ already governs autonomous side effects through <strong>Action Proposals</strong>: when an agent
        wants to do something in the real world it creates an <code class="rounded bg-gray-100 px-1">ActionProposal</code>
        (status <code class="rounded bg-gray-100 px-1">pending</code>), which a deterministic five-dimension
        <code class="rounded bg-gray-100 px-1">DecisionRubric</code> scores. Agent Policies add a
        <strong>versioned, per-agent layer</strong> on top of that rubric: the policy reads the rubric score
        plus the proposal's risk and target, and returns a verdict. The proposal then ends
        <code class="rounded bg-gray-100 px-1">executed</code> (auto), waits for a human, or is rejected.
    </p>

    <x-docs.callout type="info">
        Policies are <strong>backward-compatible and opt-in</strong>. With the feature flag off, or when no
        enabled policy resolves for an agent, nothing changes — the existing approval behavior is preserved.
    </x-docs.callout>

    {{-- Versioning --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Versioned, rollback-able rules</h2>
    <p class="mt-2 text-sm text-gray-600">
        An <code class="rounded bg-gray-100 px-1">AgentPolicy</code> row is just a current-pointer; the
        authoritative rules live on a pinned <code class="rounded bg-gray-100 px-1">AgentPolicyVersion</code>.
        Changing a policy mints a new version rather than mutating in place, so every change is an immutable,
        auditable, rollback-able event. A policy is either <code class="rounded bg-gray-100 px-1">active</code>
        or <code class="rounded bg-gray-100 px-1">archived</code>.
    </p>

    {{-- Resolution --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Policy resolution (precedence)</h2>
    <p class="mt-2 text-sm text-gray-600">
        <code class="rounded bg-gray-100 px-1">AgentPolicyResolver</code> picks the effective policy for a
        <em>(team, agent)</em> pair by precedence:
    </p>
    <ol class="mt-3 list-inside list-decimal space-y-1.5 text-sm text-gray-600">
        <li>The agent-specific active + enabled policy, if one exists;</li>
        <li>otherwise the team-default policy (one with a null <code class="font-mono text-xs">agent_id</code>);</li>
        <li>otherwise none — the caller keeps its legacy behavior.</li>
    </ol>

    {{-- Evaluation rules --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">What the evaluator checks</h2>
    <p class="mt-2 text-sm text-gray-600">
        <code class="rounded bg-gray-100 px-1">PolicyEvaluator</code> is pure and deterministic. It walks the
        resolved version's rules in order; anything uncertain falls back to "require human":
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Rule</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Effect</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Denied target types</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Hard deny — e.g. migrations are never auto-runnable.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Allow list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">When set, anything outside it is held for review (not denied).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Sensitive paths</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Raise the effective risk and force review — "be careful", not a hard block.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Spend / frequency caps</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Exceeding the per-window cap holds for review; it never auto-drops the action.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Risk ceiling</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Risk above the policy's ceiling → review. Critical risk always requires a human.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Auto-execute opt-in</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Auto only when explicitly enabled and the rubric total clears the policy threshold.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Explain / replay --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Proposal explain &amp; faithful replay</h2>
    <p class="mt-2 text-sm text-gray-600">
        Because the policy <em>version</em> in force is pinned onto the proposal,
        <code class="rounded bg-gray-100 px-1">ProposalExplainResolver</code> can reconstruct a reproducible
        "why" record long after the policy changes or is rolled back. The explain payload returns the proposal
        details, the rubric breakdown, the exact policy verdict, the pinned version's rules, and the proposal's
        lineage — so an auditor sees precisely the rules that produced the decision. This is surfaced via the
        <code class="rounded bg-gray-100 px-1">action_proposal_explain</code> MCP tool.
    </p>

    {{-- UI --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">In the UI</h2>
    <ul class="mt-3 list-inside list-disc space-y-1.5 text-sm text-gray-600">
        <li><a href="{{ route('policies.index') }}" class="text-primary-600 hover:underline">Policies</a> lists policies with their status, scope (agent or team-default), and current version.</li>
        <li><a href="{{ route('policies.create') }}" class="text-primary-600 hover:underline">Create</a> defines a new policy and its first rule set.</li>
        <li>The policy detail page shows version history and lets you roll back to an earlier version.</li>
        <li>Proposed actions and their verdicts appear in the <a href="{{ route('approvals.index') }}" class="text-primary-600 hover:underline">Approval Inbox</a>.</li>
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_policy_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List policies for the team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_policy_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Fetch a policy with its current version rules.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_policy_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a policy and its first version.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_policy_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Mint a new version with revised rules.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_policy_rollback</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Roll the current pointer back to an earlier version.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">action_proposal_list / _get / _approve / _reject / _explain</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Inspect and decide the proposals a policy governs, and explain any verdict.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'approvals') }}" class="text-primary-600 hover:underline">Approvals &amp; Human Tasks</a> — where held proposals land for a human decision.</li>
        <li><a href="{{ route('docs.show', 'agents') }}" class="text-primary-600 hover:underline">Agents</a> — the subject a policy governs.</li>
        <li><a href="{{ route('docs.show', 'security') }}" class="text-primary-600 hover:underline">Security</a> — the broader trust and guardrail model.</li>
        <li><a href="{{ route('docs.show', 'budget') }}" class="text-primary-600 hover:underline">Budget &amp; Cost</a> — backs the policy's spend caps.</li>
    </ul>
</x-layouts.docs>
