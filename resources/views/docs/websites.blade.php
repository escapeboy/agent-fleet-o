<x-layouts.docs
    title="Website Builder"
    description="Generate, edit, and publish full websites with FleetQ — AI generation from a prompt, visual GrapesJS editor, plugin packages, dynamic content widgets, and ZIP or Vercel publishing."
    page="websites"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Website Builder</h1>
    <p class="mt-4 text-gray-600">
        FleetQ ships a full <strong>AI website builder</strong>. Describe what you want, let an agent generate
        the site, refine it in a visual editor, and publish it — all from the same platform your agents,
        workflows, and chatbots live in. Forms post back as Signals, contact flows trigger workflows,
        and your existing Chatbot can be embedded as a widget.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A marketing team types "Landing page for an AI coding assistant with
        pricing, FAQ, contact form, and a chatbot." FleetQ generates a multi-page site, assigns a theme, and
        drops the contact form into Signals. The team edits copy visually in the GrapesJS editor, publishes
        to Vercel, and the whole thing ships in minutes — without writing a line of HTML.</em>
    </p>

    {{-- Capabilities --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">What you can do</h2>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">AI generation from a prompt</p>
            <p class="mt-1 text-sm text-gray-600">
                Two-phase generation: an LLM plans the site structure (pages, sections, nav), then generates
                HTML for each page. Every page is automatically converted to GrapesJS project data so it's
                editable visually the moment it's created.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Visual GrapesJS editor</p>
            <p class="mt-1 text-sm text-gray-600">
                Drag-and-drop block editor with style manager, asset library, and device preview. Each page
                is stored as both rendered HTML and GrapesJS project JSON — no lock-in.
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Plugin packages</p>
            <p class="mt-1 text-sm text-gray-600">
                Extend the builder with Composer packages. First-party packs include
                <code class="font-mono text-xs">website-plugin-core</code> (navbar, hero, pricing, CTA, footer),
                <code class="font-mono text-xs">website-plugin-blog</code>, <code class="font-mono text-xs">website-plugin-forms</code>,
                <code class="font-mono text-xs">website-plugin-chatbot</code>, and
                <code class="font-mono text-xs">website-plugin-ecommerce</code> (Stripe/Paddle checkout).
            </p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Publishing drivers</p>
            <p class="mt-1 text-sm text-gray-600">
                Export a site as a <strong>ZIP</strong> (static HTML + assets) for manual hosting, or deploy
                directly to <strong>Vercel</strong> via an API token. Each publish creates an immutable revision
                you can roll back.
            </p>
        </div>
    </div>

    {{-- Architecture --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Architecture</h2>
    <p class="mt-2 text-sm text-gray-600">
        Four models form the core of the Website domain. Every record is team-scoped and soft-deletable.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Model</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">What it stores</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">Website</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Name, slug, theme, status (<code class="rounded bg-gray-100 px-1">draft</code>/<code class="rounded bg-gray-100 px-1">published</code>/<code class="rounded bg-gray-100 px-1">archived</code>), publish driver config, <code class="rounded bg-gray-100 px-1">content_version</code> for cache invalidation.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">WebsitePage</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Title, slug, rendered HTML, GrapesJS project JSON, SEO metadata, order, publish status.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">WebsiteAsset</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Uploaded images, fonts, and files. Served from the assets tab in the editor.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">WebsiteDeployment</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">One row per publish. Tracks the driver (zip/vercel), target URL, status, deploy log, and timestamps — your rollback history lives here.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- AI generation flow --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">AI generation flow</h2>
    <p class="mt-2 text-sm text-gray-600">
        <code class="rounded bg-gray-100 px-1 text-xs">GenerateWebsiteFromPromptAction</code> runs a two-phase pipeline:
    </p>
    <ol class="mt-3 list-inside list-decimal space-y-2 text-sm text-gray-600">
        <li><strong>Plan</strong> — one LLM call parses the prompt into a site plan: <code class="rounded bg-gray-100 px-1">{ name, pages[], each page: { title, slug, type, sections[] } }</code>.</li>
        <li><strong>Generate</strong> — one LLM call per page produces the HTML body. Each output is passed through <code class="rounded bg-gray-100 px-1 text-xs">HtmlSanitizer::purify()</code> (HTMLPurifier) before storage.</li>
        <li><strong>Convert</strong> — each HTML body is run through <code class="rounded bg-gray-100 px-1 text-xs">GrapesJsExporter::htmlToProjectData()</code> so the page is immediately editable in the visual builder.</li>
        <li><strong>Persist</strong> — <code class="rounded bg-gray-100 px-1 text-xs">CreateWebsiteAction</code> + <code class="rounded bg-gray-100 px-1 text-xs">CreateWebsitePageAction</code> create the rows, auto-wire the navbar, and return the Website.</li>
    </ol>

    <x-docs.code lang="json" title="Generate via API">
// POST /api/v1/websites/generate
{
  "prompt": "Landing page for an AI coding assistant: hero, features, pricing, FAQ, contact form",
  "theme": "minimal-dark"
}</x-docs.code>

    {{-- Dynamic content widgets --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Dynamic content widgets</h2>
    <p class="mt-2 text-sm text-gray-600">
        Pages can include server-rendered placeholders that are replaced at publish time (and cached in Redis).
        These are useful when you want a page to stay in sync with your blog or sitemap without re-publishing
        every time content changes.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Placeholder</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Behaviour</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">&lt;!-- fleetq:recent-posts --&gt;</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Injects the N most recent blog posts for the site.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">&lt;!-- fleetq:page-list --&gt;</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Injects a list of all published pages (auto-nav).</td>
                </tr>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-sm text-gray-600">
        Widget output is cached in Redis keyed by <code class="rounded bg-gray-100 px-1 text-xs">website_id</code> +
        <code class="rounded bg-gray-100 px-1 text-xs">content_version</code>. Updating a page bumps the version
        and invalidates the cache. Hit/miss metrics are recorded for observability.
    </p>

    <x-docs.callout type="info">
        When the navbar is auto-synced from the page list, any renamed or reordered pages are reflected on
        <em>every</em> published page without manual edits. A broken-link rewriter also updates stale internal
        <code class="rounded bg-gray-100 px-1 text-xs">&lt;a href&gt;</code>s when a page slug changes.
    </x-docs.callout>

    {{-- Publishing --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Publishing drivers</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Driver</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">How it works</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">zip</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Renders every published page, collects assets, and returns a signed download URL for a
                        ZIP file containing static HTML. Drop the archive into any static host (Cloudflare Pages,
                        Netlify drop, Nginx, S3).
                    </td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">vercel</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Uses a team-scoped Vercel API token (stored as a <a href="{{ route('docs.show', 'credentials') }}" class="text-primary-600 hover:underline">Credential</a>)
                        to deploy the ZIP as a new Vercel deployment. Custom domain, preview URLs, and rollback
                        are handled by Vercel natively.
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.code lang="bash" title="Publish via API">
curl -X POST {{ url('/api/v1/websites/WEBSITE_ID/publish') }} \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"driver": "vercel"}'</x-docs.code>

    {{-- Forms & signals --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Forms → Signals</h2>
    <p class="mt-2 text-sm text-gray-600">
        Contact forms and newsletter sign-ups posted from a FleetQ-hosted site land at a stable public endpoint:
    </p>
    <x-docs.code lang="text">POST /api/public/sites/{slug}/forms/{formId}</x-docs.code>
    <p class="mt-3 text-sm text-gray-600">
        The <code class="rounded bg-gray-100 px-1 text-xs">formId</code> is validated against the saved page
        graph (IDOR guard), payloads pass through a honeypot + rate-limiter, and the resulting submission is
        ingested as a <a href="{{ route('docs.show', 'signals') }}" class="text-primary-600 hover:underline">Signal</a>.
        Add a <a href="{{ route('docs.show', 'triggers') }}" class="text-primary-600 hover:underline">Trigger Rule</a>
        on the binding to route form submissions into a workflow or experiment automatically.
    </p>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        The Website domain ships 17 granular MCP tools — available on <code class="font-mono text-xs">/mcp/full</code>
        and via stdio. The FleetQ assistant can call every one of them on your behalf.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_list</td><td class="py-2 pr-4 text-xs text-gray-600">List all websites for the team.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_get</td><td class="py-2 pr-4 text-xs text-gray-600">Retrieve a single website with metadata.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_create</td><td class="py-2 pr-4 text-xs text-gray-600">Create a new empty website.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_update</td><td class="py-2 pr-4 text-xs text-gray-600">Update name, slug, theme, or publish driver config.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_delete</td><td class="py-2 pr-4 text-xs text-gray-600">Soft-delete a website.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_generate</td><td class="py-2 pr-4 text-xs text-gray-600">AI-generate a full multi-page site from a plain-text prompt.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_deploy</td><td class="py-2 pr-4 text-xs text-gray-600">Publish the current version via the configured driver (zip or vercel).</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_unpublish</td><td class="py-2 pr-4 text-xs text-gray-600">Tear down the live deployment.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_export</td><td class="py-2 pr-4 text-xs text-gray-600">Return a signed ZIP download URL without deploying.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_analytics</td><td class="py-2 pr-4 text-xs text-gray-600">Traffic, form submission, and widget cache hit/miss metrics.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_deployment_list</td><td class="py-2 pr-4 text-xs text-gray-600">History of deploys (driver, status, URL, timestamps) for rollback.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_page_list</td><td class="py-2 pr-4 text-xs text-gray-600">List pages of a website.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_page_get</td><td class="py-2 pr-4 text-xs text-gray-600">Retrieve a single page.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_page_create</td><td class="py-2 pr-4 text-xs text-gray-600">Create a new page with HTML body + GrapesJS JSON.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_page_update</td><td class="py-2 pr-4 text-xs text-gray-600">Update page content or SEO metadata.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_page_publish</td><td class="py-2 pr-4 text-xs text-gray-600">Publish an individual page.</td></tr>
                <tr><td class="py-2 pl-4 pr-6 font-mono text-xs text-gray-900">website_page_unpublish</td><td class="py-2 pr-4 text-xs text-gray-600">Hide an individual page from the live site.</td></tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        The assistant understands the dynamic content widget vocabulary. Ask it to "add a recent-posts block
        to the home page" and it will drop the correct HTML comment into the page for you.
    </x-docs.callout>

    {{-- API endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <p class="mt-2 text-sm text-gray-600">
        All endpoints are under <code class="rounded bg-gray-100 px-1 text-xs">/api/v1/websites</code> and
        require a Sanctum bearer token. Full OpenAPI 3.1 schema at
        <a href="/docs/api" class="text-primary-600 hover:underline">/docs/api</a>.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all websites (cursor-paginated).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new website manually.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/generate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">AI-generate a website from a prompt.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a website with its pages.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">PUT</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update a website (name, slug, theme, status).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">DELETE</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Soft-delete a website.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}/publish</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Publish via selected driver (zip / vercel).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}/unpublish</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Tear down the published copy.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}/pages</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List pages for a website.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}/pages</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a page (HTML body + GrapesJS JSON).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">PUT</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}/pages/{pageId}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update a page.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}/pages/{pageId}/publish</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Publish an individual page.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}/assets</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Upload an asset.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-700">/api/v1/websites/{id}/export</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Returns a signed ZIP download URL.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="warning">
        Generated HTML is always sanitised with HTMLPurifier before storage. Do not bypass
        <code class="rounded bg-gray-100 px-1 text-xs">HtmlSanitizer::purify()</code> when writing custom
        page content through the API — inline JavaScript and dangerous attributes will be stripped on save.
    </x-docs.callout>
</x-layouts.docs>
