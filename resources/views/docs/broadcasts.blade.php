<x-layouts.docs
    title="Broadcasts"
    description="Send a one-time mass email to an Audience — with an approval gate, budget guard, and recipient chunking across batched background jobs so large audiences never block."
    page="broadcasts"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Broadcasts</h1>
    <p class="mt-4 text-gray-600">
        A <strong>Broadcast</strong> is a one-time mass email sent to every subscribed member of an
        <strong>Audience</strong>. It carries its own approval state and budget guard, then fans delivery out
        across batched background jobs so a large recipient list never runs as a single long synchronous loop.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A team drafts a product-update email to a 40,000-member audience. They
        request approval; an admin approves it. The broadcast checks the budget, splits recipients into chunks
        of 100, and dispatches a batch of jobs. Once every chunk settles, the broadcast rolls to a terminal
        status.</em>
    </p>

    {{-- Lifecycle --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Broadcast lifecycle</h2>
    <ol class="mt-4 space-y-3 text-sm text-gray-600">
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">1</span>
            <span>
                <strong class="text-gray-900">Draft</strong> — create the broadcast with a name, subject, body,
                and target audience. Status <code class="rounded bg-gray-100 px-1">draft</code>.
            </span>
        </li>
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">2</span>
            <span>
                <strong class="text-gray-900">Pending approval</strong> — request approval. The broadcast
                records <code class="rounded bg-gray-100 px-1">requested_by</code> and moves to
                <code class="rounded bg-gray-100 px-1">pending_approval</code>. Approval state lives on the
                broadcast itself rather than a shared ApprovalRequest.
            </span>
        </li>
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">3</span>
            <span>
                <strong class="text-gray-900">Approved</strong> — an authorised user approves; the broadcast
                records <code class="rounded bg-gray-100 px-1">approved_by</code> /
                <code class="rounded bg-gray-100 px-1">approved_at</code> and is ready to send.
            </span>
        </li>
        <li class="flex gap-3">
            <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-100 text-xs font-bold text-primary-700">4</span>
            <span>
                <strong class="text-gray-900">Sending → Sent / Failed</strong> — delivery fans out (see below).
                The broadcast settles to <code class="rounded bg-gray-100 px-1">sent</code> when at least one
                recipient was delivered, otherwise <code class="rounded bg-gray-100 px-1">failed</code>. It can
                also be <code class="rounded bg-gray-100 px-1">cancelled</code> before sending.
            </span>
        </li>
    </ol>

    {{-- Delivery --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Recipient chunking &amp; delivery</h2>
    <p class="mt-2 text-sm text-gray-600">
        Delivery is orchestrated by <code class="rounded bg-gray-100 px-1">SendBroadcastJob</code>, which:
    </p>
    <ol class="mt-3 list-inside list-decimal space-y-1.5 text-sm text-gray-600">
        <li>Gates on budget via <code class="font-mono text-xs">BroadcastBudgetGuard::assertCanSend()</code> using the pending recipient count.</li>
        <li>Splits the pending <code class="font-mono text-xs">BroadcastRecipient</code> rows into chunks of <strong>100</strong> with <code class="font-mono text-xs">chunkById</code>.</li>
        <li>Dispatches a <code class="font-mono text-xs">SendBroadcastChunkJob</code> per chunk as a single <strong>job batch</strong> on the <code class="font-mono text-xs">outbound</code> queue — the per-recipient send loop lives in the chunk job, so no single job runs the whole audience.</li>
        <li>On batch <code class="font-mono text-xs">finally</code>, finalizes the broadcast to its terminal status based on how many recipients were actually sent.</li>
    </ol>

    <x-docs.callout type="info">
        Each recipient is tracked by a <code class="rounded bg-gray-100 px-1">BroadcastRecipient</code> row with
        its own status (<code class="rounded bg-gray-100 px-1">pending</code> → <code class="rounded bg-gray-100 px-1">sent</code>),
        so a retried chunk never double-sends to someone already delivered.
    </x-docs.callout>

    {{-- UI --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">In the UI</h2>
    <ul class="mt-3 list-inside list-disc space-y-1.5 text-sm text-gray-600">
        <li><a href="{{ route('broadcasts.index') }}" class="text-primary-600 hover:underline">Broadcasts</a> lists every broadcast with its audience, status, and recipient count.</li>
        <li><a href="{{ route('broadcasts.create') }}" class="text-primary-600 hover:underline">Create</a> drafts a new broadcast against an audience.</li>
        <li>The detail page shows recipient delivery progress and the approval trail.</li>
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">broadcast_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List the team's broadcasts.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">broadcast_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Fetch a broadcast with status and recipient count.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">broadcast_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Draft a broadcast for an audience.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">broadcast_request_approval</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Move a draft to pending approval.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">broadcast_approve</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Approve a pending broadcast and queue delivery.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">broadcast_cancel</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Cancel a broadcast before it sends.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Related --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Related concepts</h2>
    <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-gray-600">
        <li><a href="{{ route('docs.show', 'outbound') }}" class="text-primary-600 hover:underline">Outbound Delivery</a> — per-message delivery to chat and webhook channels.</li>
        <li><a href="{{ route('docs.show', 'approvals') }}" class="text-primary-600 hover:underline">Approvals</a> — the platform-wide human-in-the-loop pattern.</li>
        <li><a href="{{ route('docs.show', 'budget') }}" class="text-primary-600 hover:underline">Budget &amp; Cost</a> — the guard that gates a send.</li>
    </ul>
</x-layouts.docs>
