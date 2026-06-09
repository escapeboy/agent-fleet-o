<x-layouts.docs
    title="Agent Sessions"
    description="Inspect and replay long-running agent sessions — a durable, append-only timeline of every wake, tool call, LLM call, handoff, and artifact an agent produced."
    page="agent-sessions"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Agent Sessions</h1>
    <p class="mt-4 text-gray-600">
        An <strong>Agent Session</strong> is the durable record of a single agent's working life — a
        sleep/wake-capable container that accumulates an append-only timeline of everything the agent did:
        stage transitions, tool calls, LLM calls, human input, artifacts, errors, and handoffs. Where an
        experiment tracks <em>a pipeline</em>, a session tracks <em>an agent</em> across that work, so you can
        open it after the fact and watch the whole story replay.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A long-running research agent ran overnight, slept while it waited on a
        human approval, woke, called three tools, then handed off to a writer agent. The next morning you open
        the session, scrub its timeline, and see exactly which tool result led to the final artifact.</em>
    </p>

    {{-- Lifecycle --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Session lifecycle</h2>
    <p class="mt-2 text-sm text-gray-600">
        A session moves through a small status set. Sleeping sessions can be woken to rehydrate recent context;
        terminal sessions are immutable.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Status</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Meaning</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Pending</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Created but not yet started.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Active</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Currently working — new events are being appended.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Sleeping</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Paused, awaiting a wake (e.g. blocked on an approval). Waking rehydrates recent context.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Completed / Cancelled / Failed</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Terminal. The timeline is frozen and fully replayable.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Event timeline --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">The event timeline</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every meaningful moment is recorded as an immutable, sequence-numbered <code class="rounded bg-gray-100 px-1">AgentSessionEvent</code>.
        The detail page renders them newest-first and reconstructs a replay view from the full sequence.
        Event kinds include:
    </p>
    <div class="mt-4 grid gap-2 sm:grid-cols-2 text-sm text-gray-600">
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">wake</code> / <code class="text-xs">sleep</code> — session resumed or paused</div>
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">transition</code> — agent state change</div>
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">stage_started</code> / <code class="text-xs">stage_completed</code></div>
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">tool_call</code> / <code class="text-xs">tool_result</code></div>
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">llm_call</code> — an inference request</div>
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">human_input</code> — a person intervened</div>
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">artifact</code> — output produced</div>
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">handoff_out</code> / <code class="text-xs">handoff_in</code> — work passed between agents</div>
        <div class="rounded-lg border border-gray-200 p-3"><code class="text-xs">error</code> / <code class="text-xs">note</code></div>
    </div>

    {{-- UI --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">In the UI</h2>
    <ul class="mt-3 list-inside list-disc space-y-1.5 text-sm text-gray-600">
        <li>
            <a href="{{ route('agent-sessions.index') }}" class="text-primary-600 hover:underline">Agent Sessions</a>
            lists every session for the team with its agent, status, and event count.
        </li>
        <li>
            The <strong>session detail</strong> page renders the replay reconstruction alongside the raw event
            timeline (most recent 500 events). From here you can <strong>Wake</strong> a sleeping session or
            <strong>Cancel</strong> an active one (both require the <code class="rounded bg-gray-100 px-1">edit-content</code> permission).
        </li>
    </ul>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        Agents and the assistant manage sessions over MCP — including waking a slept session and replaying its
        timeline programmatically.
    </p>
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_session_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List sessions for the team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_session_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Fetch a single session with its status and counts.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_session_events</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Page through the append-only event timeline.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_session_replay</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Reconstruct the session state from its event sequence.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_session_wake</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Wake a sleeping session and rehydrate recent context.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_session_sleep</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Put an active session to sleep until woken.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_session_handoff</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Hand the session's work off to another agent.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">agent_session_cancel</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Cancel an active or sleeping session.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        Because the timeline is append-only and sequence-numbered, a replay is faithful no matter how long the
        session ran or how many times it slept — making sessions the primary tool for post-hoc debugging of
        autonomous agents.
    </x-docs.callout>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'agents') }}" class="text-primary-600 hover:underline">Agents</a> — the worker a session belongs to.</li>
        <li><a href="{{ route('docs.show', 'experiments') }}" class="text-primary-600 hover:underline">Experiments</a> — the pipeline a session often executes against.</li>
        <li><a href="{{ route('docs.show', 'audit-log') }}" class="text-primary-600 hover:underline">Audit Log</a> — the team-wide record of mutating actions.</li>
    </ul>
</x-layouts.docs>
