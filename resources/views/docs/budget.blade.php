<x-layouts.docs
    title="Budget & Cost Control"
    description="FleetQ budget management — credit system, reservations, usage counters, plan limits, and automatic spend alerts."
    page="budget"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Budget &amp; Cost Control</h1>
    <p class="mt-4 text-gray-600">
        FleetQ gives you full control over AI spending. Set team-level and project-level budgets, receive alerts
        before limits are hit, and rely on the platform to automatically pause runaway experiments.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A startup sets a €50/month team budget. When 80% is consumed
        (€40), the team owner receives an email and in-app notification. At 100%, all new experiments and
        project runs are blocked until the budget is increased or the month resets.</em>
    </p>

    {{-- Credit system --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Credit system</h2>
    <div class="mt-4 flex items-center gap-4 rounded-xl border border-gray-200 bg-gray-50 px-6 py-5">
        <div class="text-4xl font-bold text-primary-600">1</div>
        <div>
            <p class="font-semibold text-gray-900">credit = $0.001 USD</p>
            <p class="mt-1 text-sm text-gray-600">Credits are the internal unit of AI spend. Token costs are converted to credits at inference time using provider-specific pricing tables.</p>
        </div>
    </div>

    {{-- Reservations --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Budget reservations</h2>
    <p class="mt-2 text-sm text-gray-600">
        Before an experiment stage runs, FleetQ <strong>reserves</strong> the estimated cost with a
        <strong>1.5× safety multiplier</strong>. This prevents overspend when token estimates are imprecise
        (e.g. when the model generates more output than expected).
    </p>
    <p class="mt-2 text-sm text-gray-600">
        After the stage completes, the actual cost is <strong>settled</strong> — the reservation is released
        and the exact amount is charged. The difference is returned to your balance.
    </p>
    <x-docs.callout type="info">
        Reservations use <code class="text-xs">SELECT FOR UPDATE</code> (pessimistic locking) to prevent
        concurrent experiments from overclaiming the same budget allocation.
    </x-docs.callout>

    {{-- Usage counters --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Usage counters</h2>
    <p class="mt-2 text-sm text-gray-600">
        In addition to credit-based budgets, FleetQ enforces monthly usage limits for your plan:
    </p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div class="rounded-lg border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Runs</p>
            <p class="mt-1 text-sm text-gray-600">Total experiment and project runs per month.</p>
        </div>
        <div class="rounded-lg border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Outbound sends</p>
            <p class="mt-1 text-sm text-gray-600">Emails, Slack messages, and webhook deliveries per month.</p>
        </div>
    </div>
    <p class="mt-2 text-xs text-gray-500">
        Counters are stored in Redis with atomic Lua increments — preventing TOCTOU races where two concurrent
        requests both read the counter before either increments it.
    </p>

    {{-- Alerts --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Spend alerts</h2>
    <p class="mt-2 text-sm text-gray-600">FleetQ checks usage every hour and sends alerts at:</p>
    <div class="mt-3 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
            <p class="font-semibold text-amber-900">80% threshold</p>
            <p class="mt-1 text-sm text-amber-700">Warning notification. Runs continue but you should review your budget.</p>
        </div>
        <div class="rounded-xl border border-red-200 bg-red-50 p-4">
            <p class="font-semibold text-red-900">100% threshold</p>
            <p class="mt-1 text-sm text-red-700">Critical notification. New runs are blocked. Increase budget to resume.</p>
        </div>
    </div>

    {{-- Plan structural limits --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Plan structural limits</h2>
    <p class="mt-2 text-sm text-gray-600">
        Beyond credit budgets, plans enforce hard caps on the number of entities you can create:
    </p>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-4 text-left font-semibold text-gray-700">Metric</th>
                    <th class="py-3 pr-4 text-center font-semibold text-gray-700">Free</th>
                    <th class="py-3 pr-4 text-center font-semibold text-gray-700">Starter</th>
                    <th class="py-3 pr-4 text-center font-semibold text-gray-700">Pro</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 text-center text-xs">
                <tr><td class="py-2.5 pl-4 pr-4 text-left text-gray-700">Team members</td><td class="py-2.5 pr-4 text-gray-600">3</td><td class="py-2.5 pr-4 text-gray-600">10</td><td class="py-2.5 pr-4 text-gray-600">Unlimited</td></tr>
                <tr><td class="py-2.5 pl-4 pr-4 text-left text-gray-700">Agents</td><td class="py-2.5 pr-4 text-gray-600">5</td><td class="py-2.5 pr-4 text-gray-600">25</td><td class="py-2.5 pr-4 text-gray-600">Unlimited</td></tr>
                <tr><td class="py-2.5 pl-4 pr-4 text-left text-gray-700">Active experiments</td><td class="py-2.5 pr-4 text-gray-600">2</td><td class="py-2.5 pr-4 text-gray-600">10</td><td class="py-2.5 pr-4 text-gray-600">Unlimited</td></tr>
                <tr><td class="py-2.5 pl-4 pr-4 text-left text-gray-700">Saved workflows</td><td class="py-2.5 pr-4 text-gray-600">3</td><td class="py-2.5 pr-4 text-gray-600">15</td><td class="py-2.5 pr-4 text-gray-600">Unlimited</td></tr>
            </tbody>
        </table>
    </div>

    {{-- Viewing budget --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Viewing your budget</h2>
    <p class="mt-2 text-sm text-gray-600">
        Check current spend, forecasts, and usage counters via the API:
    </p>
    <x-docs.code lang="bash">
GET {{ url('/api/v1/budget') }}
Authorization: Bearer YOUR_TOKEN</x-docs.code>
</x-layouts.docs>
