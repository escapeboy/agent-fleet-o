<x-layouts.docs
    title="Email Templates & Themes"
    description="FleetQ includes a full email template system for outbound delivery. Create branded email themes, author reusable templates with dynamic variables, and use AI to generate professional HTML email content in seconds."
    page="email"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Email Templates &amp; Themes</h1>
    <p class="mt-4 text-gray-600">
        FleetQ includes a full email template system for outbound email delivery. <strong>Themes</strong> define
        the visual branding applied to every email your team sends. <strong>Templates</strong> define the content
        and structure — with dynamic variables that are substituted at send time. Both resources are team-scoped
        and fully manageable via the UI, API, or MCP tools.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A growth team creates a "Company Brand" theme with their primary colour and
        logo. They then author a "Weekly Digest" template that references <code class="rounded bg-gray-100 px-1">@{{ $agent.name }}</code>
        and <code class="rounded bg-gray-100 px-1">@{{ $signal.title }}</code>. Each Friday, the "Weekly Report"
        experiment renders the template with live data and delivers it via the SMTP outbound connector.</em>
    </p>

    {{-- Email Themes --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Email Themes</h2>
    <p class="mt-2 text-sm text-gray-600">
        A theme defines the visual styling that wraps every email template: colours, fonts, logo, and footer copy.
        Assign one theme to many templates to keep branding consistent across all outbound communications.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Field</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">name</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Human-readable label for the theme (e.g. "Company Brand", "Dark Mode").</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">description</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Optional free-text description of when/how the theme should be used.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">primary_color</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Hex colour used for buttons, headings, and accent elements (e.g. <code class="rounded bg-gray-100 px-1">#3B82F6</code>).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">secondary_color</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Hex colour for secondary elements such as borders and muted backgrounds.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">logo_url</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Publicly accessible URL to the logo image rendered in the email header.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">footer_text</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Plain-text or minimal HTML displayed in every email footer (company name, unsubscribe notice, address).</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        Create a theme per brand or environment (e.g. "Production", "Staging") so all emails rendered in that
        context share a consistent look without any per-template configuration.
    </x-docs.callout>

    {{-- Email Templates --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Email Templates</h2>
    <p class="mt-2 text-sm text-gray-600">
        Templates define the subject line, HTML body, and metadata for a reusable email. They reference a theme
        for visual styling and declare dynamic variables that are substituted at delivery time.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Field</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">name</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Internal name for the template (e.g. "Weekly Digest", "Alert: Experiment Failed").</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">subject</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Email subject line. Supports variable interpolation — e.g. <code class="rounded bg-gray-100 px-1">@{{ $signal.title }}</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">body</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Full HTML body with optional dynamic variable placeholders. AI-generated templates produce production-ready HTML.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">theme_id</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">UUID of the email theme to apply. The theme's colours and logo are injected into the rendered output.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">category</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">One of: <code class="rounded bg-gray-100 px-1">notification</code>, <code class="rounded bg-gray-100 px-1">digest</code>, <code class="rounded bg-gray-100 px-1">alert</code>, <code class="rounded bg-gray-100 px-1">report</code>, <code class="rounded bg-gray-100 px-1">custom</code>.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Dynamic variables --}}
    <h3 class="mt-8 text-base font-semibold text-gray-900">Dynamic variables</h3>
    <p class="mt-2 text-sm text-gray-600">
        Templates support Blade-style double-curly-brace variables. At delivery time, FleetQ substitutes real
        values from the experiment run or outbound payload. Common variables include:
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Variable</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Resolves to</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">@{{ $signal.title }}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Title of the inbound signal that triggered the run.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">@{{ $agent.name }}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Display name of the agent that produced the output.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">@{{ $experiment.title }}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Title of the parent experiment.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">@{{ $output }}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Raw text output from the last completed pipeline stage.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">@{{ $team.name }}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Name of the team sending the email.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="info">
        Variable substitution uses simple string replacement. Undefined variables render as an empty string
        rather than causing a delivery failure.
    </x-docs.callout>

    {{-- Template categories --}}
    <h3 class="mt-8 text-base font-semibold text-gray-900">Template categories</h3>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Category</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Typical use</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">notification</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Single-event alerts triggered immediately by agent output or state changes.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">digest</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Periodic summaries (daily, weekly) that batch multiple signals or results.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">alert</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">High-priority messages for failures, budget overruns, or SLA breaches.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">report</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Structured outputs sent at the end of a project run or experiment.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">custom</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Anything that doesn't fit the above — promotional, transactional, etc.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- MJML rendering --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MJML rendering microservice</h2>
    <p class="mt-2 text-sm text-gray-600">
        Email templates can be authored in <a href="https://mjml.io" class="text-primary-600 hover:underline" target="_blank" rel="noopener">MJML</a>
        — a responsive email markup language that compiles to inlined, table-based HTML compatible with every
        mail client. FleetQ ships an <strong>MJML rendering microservice</strong> as a Docker sidecar so you
        never need to install the MJML CLI or Node.js on the application container.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Component</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Details</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Docker service</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600"><code class="rounded bg-gray-100 px-1">docker/mjml/</code> — a tiny Node.js container running the MJML HTTP API.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Service URL</td>
                    <td class="py-2.5 pr-4 font-mono text-xs text-gray-700">MJML_SERVER_URL=http://mjml:15500</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-medium text-gray-900">Renderer class</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600"><code class="rounded bg-gray-100 px-1">MjmlRenderer</code> posts template bodies to the sidecar and returns the compiled HTML. Falls back to a plain-HTML pass-through when the service is unreachable.</td>
                </tr>
            </tbody>
        </table>
    </div>
    <x-docs.callout type="tip">
        Write your template body with MJML tags (<code class="font-mono text-xs">&lt;mj-section&gt;</code>,
        <code class="font-mono text-xs">&lt;mj-button&gt;</code>, …) and FleetQ compiles to bulletproof HTML at
        send time. The AI generator (below) can also produce MJML directly if you ask for it.
    </x-docs.callout>

    {{-- AI generation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">AI template generation</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ can generate a complete, professionally styled HTML email template from a plain-text prompt. The
        AI matches the structure and tone you describe, embeds your theme colours, and outputs clean HTML that
        is ready to send. Use the <code class="rounded bg-gray-100 px-1">email_template_generate</code> MCP tool
        or the REST API endpoint.
    </p>

    <x-docs.code lang="bash">
# Generate a template via MCP (stdio or HTTP)
email_template_generate({
  "template_id": "018f1a2b-...",
  "prompt": "Write a weekly digest email that summarises the top 3 signals processed this week. Include a clear CTA to view the full report in FleetQ."
})</x-docs.code>

    <x-docs.code lang="bash">
# Generate via REST API
curl -X POST https://your-instance.example/api/v1/email-templates/{id}/generate \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"prompt": "Write a weekly digest email that summarises the top 3 signals processed this week."}'</x-docs.code>

    <x-docs.callout type="tip">
        Describe the <em>purpose</em> and <em>audience</em> of the email in your prompt for best results.
        For example: "Professional alert email for a DevOps team notifying them that an experiment has failed,
        including the experiment name and a link to the failure log."
    </x-docs.callout>

    <p class="mt-4 text-sm text-gray-600">
        The FleetQ assistant can also generate full email <em>themes</em> (not just templates) via the
        <code class="rounded bg-gray-100 px-1 text-xs">email_theme_create</code> tool. Ask it to "create a
        dark-mode theme for our Acme product" and it will pick a cohesive colour palette, drop in the logo,
        and craft a footer — all in one turn.
    </p>

    {{-- Using templates with outbound --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Using templates with outbound delivery</h2>
    <p class="mt-2 text-sm text-gray-600">
        Email templates are consumed by outbound email connectors (SMTP Email, Email). When an experiment stage
        or workflow node produces an outbound proposal, it references a template by ID and passes a data payload
        for variable substitution. FleetQ renders the template, injects theme styling, and delivers the final
        HTML via the configured connector.
    </p>

    <p class="mt-3 text-sm text-gray-600">
        The typical flow is:
    </p>

    <ol class="mt-3 list-inside list-decimal space-y-1.5 text-sm text-gray-600">
        <li>Create a theme and one or more templates in <a href="/signals/entities" class="text-primary-600 hover:underline">Settings → Email</a> or via the API.</li>
        <li>Configure an <strong>SMTP Email</strong> or <strong>Email</strong> outbound connector, pointing it at your template.</li>
        <li>In your experiment or workflow, add an outbound step that targets the connector and supplies data variables.</li>
        <li>When the step executes, FleetQ substitutes variables, wraps the body with the theme, and delivers the email.</li>
    </ol>

    <x-docs.callout type="info">
        Each outbound delivery is recorded as an <strong>OutboundAction</strong> with status, timestamps, and the
        rendered subject/body. You can inspect these in the Experiment detail → Outbound tab.
    </x-docs.callout>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        All email theme and template operations are available as MCP tools, giving AI agents full programmatic
        access without needing the REST API.
    </p>

    <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-gray-500">Theme tools</h3>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_theme_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all email themes for the current team.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_theme_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a single theme by ID, including all styling fields.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_theme_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new theme with name, colours, logo URL, and footer text.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_theme_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update any fields on an existing theme.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_theme_delete</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a theme. Templates referencing it will lose their theme association.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-gray-500">Template tools</h3>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Tool</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_template_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all templates, optionally filtered by category.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_template_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a single template including its full HTML body.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_template_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a template with name, subject, body, theme reference, and category.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_template_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update any field on an existing template.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_template_delete</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a template permanently.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">email_template_generate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Generate professional HTML content for an existing template from a plain-text prompt using AI.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <p class="mt-2 text-sm text-gray-600">
        All endpoints require a Sanctum bearer token and respect team scoping. Full schema is available at
        <a href="/docs/api" class="text-primary-600 hover:underline">/docs/api</a> (OpenAPI 3.1).
    </p>

    <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-gray-500">Email Themes — <code class="font-mono text-xs">/api/v1/email-themes</code></h3>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method &amp; Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">GET /api/v1/email-themes</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all themes (cursor-paginated).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">GET /api/v1/email-themes/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a single theme.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">POST /api/v1/email-themes</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new theme.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">PUT /api/v1/email-themes/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update an existing theme.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">DELETE /api/v1/email-themes/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a theme.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-sm font-semibold uppercase tracking-wide text-gray-500">Email Templates — <code class="font-mono text-xs">/api/v1/email-templates</code></h3>
    <div class="mt-3 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method &amp; Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Purpose</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">GET /api/v1/email-templates</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all templates, optionally filtered by <code class="rounded bg-gray-100 px-1">category</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">GET /api/v1/email-templates/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve a single template including HTML body.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">POST /api/v1/email-templates</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new template.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">PUT /api/v1/email-templates/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update an existing template.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">DELETE /api/v1/email-templates/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a template.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs text-gray-900">POST /api/v1/email-templates/{id}/generate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Generate AI-produced HTML content for the template. Body: <code class="rounded bg-gray-100 px-1">{"prompt": "..."}</code>.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="tip">
        Explore the interactive API schema at <a href="/docs/api" class="text-primary-600 hover:underline">/docs/api</a>
        to try endpoints directly against your running instance with your bearer token.
    </x-docs.callout>
</x-layouts.docs>
