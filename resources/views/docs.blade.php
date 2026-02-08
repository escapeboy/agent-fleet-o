<x-layouts.public title="API Documentation">
    <div class="mx-auto max-w-4xl px-4 py-16 sm:px-6 lg:px-8">
        <h1 class="text-3xl font-bold tracking-tight text-gray-900">API Documentation</h1>
        <p class="mt-4 text-lg text-gray-600">Integrate Agent Fleet into your workflows with our REST API.</p>

        {{-- Authentication --}}
        <section class="mt-12">
            <h2 class="text-2xl font-bold text-gray-900">Authentication</h2>
            <p class="mt-4 text-sm text-gray-600">
                All API requests require a Bearer token. Create tokens in
                <strong>Team Settings &rarr; API Tokens</strong>. Tokens are scoped to your current team.
            </p>
            <div class="mt-4 rounded-lg bg-gray-900 p-4">
                <pre class="text-sm text-gray-100"><code>curl -H "Authorization: Bearer YOUR_TOKEN" \
     {{ url('/api/experiments') }}</code></pre>
            </div>
        </section>

        {{-- Base URL --}}
        <section class="mt-12">
            <h2 class="text-2xl font-bold text-gray-900">Base URL</h2>
            <div class="mt-4 rounded-lg bg-gray-50 border border-gray-200 p-4">
                <code class="text-sm font-medium text-gray-900">{{ url('/api') }}</code>
            </div>
        </section>

        {{-- Experiments --}}
        <section class="mt-12">
            <h2 class="text-2xl font-bold text-gray-900">Experiments</h2>

            {{-- List Experiments --}}
            <div class="mt-8 rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-6 py-3">
                    <span class="rounded bg-green-100 px-2 py-0.5 text-xs font-bold text-green-700">GET</span>
                    <code class="text-sm font-medium text-gray-900">/api/experiments</code>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600">List all experiments for your team. Returns paginated results.</p>

                    <h4 class="mt-4 text-sm font-semibold text-gray-900">Query Parameters</h4>
                    <table class="mt-2 w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="py-2 pr-4 text-left font-medium text-gray-500">Parameter</th>
                                <th class="py-2 pr-4 text-left font-medium text-gray-500">Type</th>
                                <th class="py-2 text-left font-medium text-gray-500">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-gray-50">
                                <td class="py-2 pr-4"><code class="text-xs">per_page</code></td>
                                <td class="py-2 pr-4 text-gray-500">integer</td>
                                <td class="py-2 text-gray-600">Results per page (default: 15)</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4"><code class="text-xs">page</code></td>
                                <td class="py-2 pr-4 text-gray-500">integer</td>
                                <td class="py-2 text-gray-600">Page number</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4 class="mt-4 text-sm font-semibold text-gray-900">Example Response</h4>
                    <div class="mt-2 rounded-lg bg-gray-900 p-4">
                        <pre class="text-sm text-gray-100"><code>{
  "data": [
    {
      "id": "01902f8a-...",
      "name": "Q1 Outreach Campaign",
      "status": "running",
      "current_stage": "ai_analysis",
      "budget_spent_cents": 4500,
      "created_at": "2026-01-15T10:30:00Z"
    }
  ],
  "links": { "next": "...", "prev": null },
  "meta": { "current_page": 1, "total": 42 }
}</code></pre>
                    </div>
                </div>
            </div>

            {{-- Get Experiment --}}
            <div class="mt-6 rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-6 py-3">
                    <span class="rounded bg-green-100 px-2 py-0.5 text-xs font-bold text-green-700">GET</span>
                    <code class="text-sm font-medium text-gray-900">/api/experiments/{id}</code>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600">Get a single experiment with its pipeline stages.</p>

                    <h4 class="mt-4 text-sm font-semibold text-gray-900">Path Parameters</h4>
                    <table class="mt-2 w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="py-2 pr-4 text-left font-medium text-gray-500">Parameter</th>
                                <th class="py-2 pr-4 text-left font-medium text-gray-500">Type</th>
                                <th class="py-2 text-left font-medium text-gray-500">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td class="py-2 pr-4"><code class="text-xs">id</code></td>
                                <td class="py-2 pr-4 text-gray-500">uuid</td>
                                <td class="py-2 text-gray-600">Experiment UUID</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        {{-- Signals --}}
        <section class="mt-12">
            <h2 class="text-2xl font-bold text-gray-900">Signals</h2>

            {{-- List Signals --}}
            <div class="mt-8 rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-6 py-3">
                    <span class="rounded bg-green-100 px-2 py-0.5 text-xs font-bold text-green-700">GET</span>
                    <code class="text-sm font-medium text-gray-900">/api/signals</code>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600">List all signals ingested by your team. Returns paginated results.</p>
                </div>
            </div>

            {{-- Create Signal --}}
            <div class="mt-6 rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-6 py-3">
                    <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700">POST</span>
                    <code class="text-sm font-medium text-gray-900">/api/signals</code>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600">Ingest a new signal into your team's pipeline.</p>

                    <h4 class="mt-4 text-sm font-semibold text-gray-900">Request Body</h4>
                    <div class="mt-2 rounded-lg bg-gray-900 p-4">
                        <pre class="text-sm text-gray-100"><code>{
  "title": "New lead from Product Hunt",
  "source": "producthunt",
  "content": {
    "url": "https://example.com",
    "description": "Interesting product launch",
    "metadata": { "score": 85 }
  }
}</code></pre>
                    </div>

                    <h4 class="mt-4 text-sm font-semibold text-gray-900">Body Parameters</h4>
                    <table class="mt-2 w-full text-sm">
                        <thead>
                            <tr class="border-b border-gray-100">
                                <th class="py-2 pr-4 text-left font-medium text-gray-500">Field</th>
                                <th class="py-2 pr-4 text-left font-medium text-gray-500">Type</th>
                                <th class="py-2 pr-4 text-left font-medium text-gray-500">Required</th>
                                <th class="py-2 text-left font-medium text-gray-500">Description</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr class="border-b border-gray-50">
                                <td class="py-2 pr-4"><code class="text-xs">title</code></td>
                                <td class="py-2 pr-4 text-gray-500">string</td>
                                <td class="py-2 pr-4 text-gray-500">Yes</td>
                                <td class="py-2 text-gray-600">Signal title (max 255 chars)</td>
                            </tr>
                            <tr class="border-b border-gray-50">
                                <td class="py-2 pr-4"><code class="text-xs">source</code></td>
                                <td class="py-2 pr-4 text-gray-500">string</td>
                                <td class="py-2 pr-4 text-gray-500">Yes</td>
                                <td class="py-2 text-gray-600">Source identifier (max 255 chars)</td>
                            </tr>
                            <tr>
                                <td class="py-2 pr-4"><code class="text-xs">content</code></td>
                                <td class="py-2 pr-4 text-gray-500">object</td>
                                <td class="py-2 pr-4 text-gray-500">Yes</td>
                                <td class="py-2 text-gray-600">Arbitrary JSON payload</td>
                            </tr>
                        </tbody>
                    </table>

                    <h4 class="mt-4 text-sm font-semibold text-gray-900">Response</h4>
                    <p class="mt-1 text-sm text-gray-600">Returns <code class="text-xs">201 Created</code> with the signal object.</p>
                </div>
            </div>
        </section>

        {{-- Webhooks --}}
        <section class="mt-12">
            <h2 class="text-2xl font-bold text-gray-900">Webhooks (Unauthenticated)</h2>
            <p class="mt-4 text-sm text-gray-600">
                These endpoints accept webhooks from external services. They use HMAC-SHA256 signature validation
                instead of Bearer tokens.
            </p>

            <div class="mt-8 rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center gap-3 border-b border-gray-200 bg-gray-50 px-6 py-3">
                    <span class="rounded bg-amber-100 px-2 py-0.5 text-xs font-bold text-amber-700">POST</span>
                    <code class="text-sm font-medium text-gray-900">/api/signals/webhook</code>
                </div>
                <div class="p-6">
                    <p class="text-sm text-gray-600">
                        Ingest signals via webhook. Include an <code class="text-xs">X-Signature</code> header
                        with the HMAC-SHA256 hash of the request body using your webhook secret.
                    </p>
                </div>
            </div>
        </section>

        {{-- Rate Limits --}}
        <section class="mt-12">
            <h2 class="text-2xl font-bold text-gray-900">Rate Limits</h2>
            <p class="mt-4 text-sm text-gray-600">
                API rate limits depend on your plan tier. Requests that exceed the limit receive a
                <code class="text-xs">429 Too Many Requests</code> response. Check the
                <code class="text-xs">Retry-After</code> header for when to retry.
            </p>
        </section>

        {{-- Errors --}}
        <section class="mt-12 mb-12">
            <h2 class="text-2xl font-bold text-gray-900">Errors</h2>
            <p class="mt-4 text-sm text-gray-600">The API returns standard HTTP status codes:</p>
            <table class="mt-4 w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200">
                        <th class="py-2 pr-4 text-left font-medium text-gray-500">Code</th>
                        <th class="py-2 text-left font-medium text-gray-500">Description</th>
                    </tr>
                </thead>
                <tbody>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 pr-4"><code class="text-xs">200</code></td>
                        <td class="py-2 text-gray-600">Success</td>
                    </tr>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 pr-4"><code class="text-xs">201</code></td>
                        <td class="py-2 text-gray-600">Created</td>
                    </tr>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 pr-4"><code class="text-xs">401</code></td>
                        <td class="py-2 text-gray-600">Unauthenticated — invalid or missing token</td>
                    </tr>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 pr-4"><code class="text-xs">403</code></td>
                        <td class="py-2 text-gray-600">Forbidden — token not scoped to this team</td>
                    </tr>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 pr-4"><code class="text-xs">404</code></td>
                        <td class="py-2 text-gray-600">Not found</td>
                    </tr>
                    <tr class="border-b border-gray-50">
                        <td class="py-2 pr-4"><code class="text-xs">422</code></td>
                        <td class="py-2 text-gray-600">Validation error</td>
                    </tr>
                    <tr>
                        <td class="py-2 pr-4"><code class="text-xs">429</code></td>
                        <td class="py-2 text-gray-600">Rate limit exceeded</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </div>
</x-layouts.public>
