<x-layouts.docs
    title="REST API Reference"
    description="FleetQ REST API reference — authentication, base URL, pagination, rate limits, error codes, and link to the interactive OpenAPI explorer."
    page="api-reference"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">REST API Reference</h1>
    <p class="mt-4 text-gray-600">
        FleetQ exposes a versioned REST API at <code class="rounded bg-gray-100 px-1 text-sm">/api/v1/</code>
        with 122 endpoints across 20 resource groups. All endpoints return JSON and follow standard HTTP conventions.
    </p>

    <a href="{{ url('/docs/api') }}"
       target="_blank"
       rel="noopener"
       class="mt-6 inline-flex items-center gap-2 rounded-lg bg-primary-600 px-5 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-primary-700">
        <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
        </svg>
        Open Interactive API Explorer →
    </a>

    {{-- Authentication --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Authentication</h2>
    <p class="mt-2 text-sm text-gray-600">
        All API requests require a <strong>Bearer token</strong>. Create tokens in
        <a href="/team" class="text-primary-600 hover:underline">Team Settings → API Tokens</a>.
        Tokens are scoped to your team — a token from Team A cannot access Team B's resources.
    </p>

    <x-docs.code lang="bash">
curl -H "Authorization: Bearer YOUR_TOKEN" \
     {{ url('/api/v1/experiments') }}</x-docs.code>

    {{-- Base URL --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Base URL</h2>
    <div class="mt-3 flex items-center gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
        <code class="text-sm font-medium text-gray-900">{{ url('/api/v1') }}</code>
    </div>

    {{-- Rate limits --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Rate limits</h2>
    <p class="mt-2 text-sm text-gray-600">
        Per-tenant rate limits apply. When exceeded, you receive <code class="rounded bg-gray-100 px-1 text-xs">429 Too Many Requests</code>.
        Check the <code class="rounded bg-gray-100 px-1 text-xs">Retry-After</code> header for the cooldown period.
        The login endpoint is additionally throttled at 5 requests per minute.
    </p>

    {{-- Pagination --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Pagination</h2>
    <p class="mt-2 text-sm text-gray-600">All list endpoints use cursor-based pagination:</p>

    <x-docs.code lang="bash">
# First page
GET /api/v1/experiments?per_page=20

# Next page (use cursor from previous response)
GET /api/v1/experiments?per_page=20&cursor=eyJpZCI6Miwic3RhcnQiOmZhbHNlfQ</x-docs.code>

    <x-docs.code lang="json" title="Paginated response shape">
{
  "data": [...],
  "links": {
    "prev": null,
    "next": "https://fleetq.net/api/v1/experiments?cursor=eyJpZCI6Miwic3RhcnQiOmZhbHNlfQ"
  },
  "meta": {
    "per_page": 20,
    "path": "https://fleetq.net/api/v1/experiments"
  }
}</x-docs.code>

    {{-- Error codes --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Error codes</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Code</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Meaning</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr><td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium">200</td><td class="py-2.5 pr-4 text-xs text-gray-600">Success</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium">201</td><td class="py-2.5 pr-4 text-xs text-gray-600">Created — resource successfully created</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium">401</td><td class="py-2.5 pr-4 text-xs text-gray-600">Unauthenticated — missing or invalid Bearer token</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium">403</td><td class="py-2.5 pr-4 text-xs text-gray-600">Forbidden — token doesn't have permission for this resource</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium">404</td><td class="py-2.5 pr-4 text-xs text-gray-600">Not found — resource doesn't exist or is not visible to your team</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium">422</td><td class="py-2.5 pr-4 text-xs text-gray-600">Validation error — response body contains field-level error messages</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium">429</td><td class="py-2.5 pr-4 text-xs text-gray-600">Rate limit exceeded — check Retry-After header</td></tr>
                <tr><td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium">500</td><td class="py-2.5 pr-4 text-xs text-gray-600">Server error — if this persists, contact support</td></tr>
            </tbody>
        </table>
    </div>

    {{-- Webhook signature --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Webhook signature verification</h2>
    <p class="mt-2 text-sm text-gray-600">
        Inbound webhook signals use HMAC-SHA256 signatures. The <code class="rounded bg-gray-100 px-1 text-xs">X-Signature</code>
        header contains <code class="rounded bg-gray-100 px-1 text-xs">sha256=&lt;hex_digest&gt;</code> computed over the raw request body.
        Additionally, <code class="rounded bg-gray-100 px-1 text-xs">X-Webhook-Timestamp</code> must be within 5 minutes of the current time
        to prevent replay attacks.
    </p>
</x-layouts.docs>
