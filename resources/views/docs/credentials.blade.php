<x-layouts.docs
    title="Credentials"
    description="Credentials securely store encrypted secrets for external services. Learn about credential types, encryption, secret rotation, BYOK, and the full Credentials API."
    page="credentials"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Credentials — Encrypted Secret Storage</h1>
    <p class="mt-4 text-gray-600">
        <strong>Credentials</strong> let you store encrypted secrets for external services in one place and
        reference them from agents, skills, and integrations. Instead of scattering API keys across your
        configuration, you define a credential once and FleetQ injects it at execution time — never exposing
        the raw secret in logs or agent prompts.
    </p>

    <p class="mt-3 text-gray-600">
        <strong>Scenario:</strong> <em>A research agent calls the OpenAI API, a CRM connector, and a Slack
        webhook. Each service needs its own secret. With Credentials, you configure each secret once and
        reference them by name — rotation, expiry, and access auditing happen automatically.</em>
    </p>

    {{-- Credential types --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Credential types</h2>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Type</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Use case</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">api_key</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Single API key for a service (e.g., OpenAI, Anthropic, SendGrid). Stored in <code class="rounded bg-gray-100 px-1">secret_data.key</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">oauth2</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">OAuth2 client credentials flow. Stores <code class="rounded bg-gray-100 px-1">client_id</code>, <code class="rounded bg-gray-100 px-1">client_secret</code>, <code class="rounded bg-gray-100 px-1">access_token</code>, and <code class="rounded bg-gray-100 px-1">refresh_token</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">basic_auth</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Username and password for HTTP Basic Authentication.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">bearer_token</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Bearer token passed in the <code class="rounded bg-gray-100 px-1">Authorization</code> header. Common for REST APIs and JWTs.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">custom</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Arbitrary key-value pairs in <code class="rounded bg-gray-100 px-1">secret_data</code>. Use for services with non-standard auth formats.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- Security --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Security &amp; encryption</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ uses <strong>per-team envelope encryption</strong> to protect all credential secrets at rest.
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li>Each team has a unique <strong>Data Encryption Key (DEK)</strong> generated automatically on team creation.</li>
        <li>The DEK itself is wrapped with the platform's <code class="rounded bg-gray-100 px-1">APP_KEY</code> (AES-256-CBC via Laravel's encryption layer).</li>
        <li>Secrets are encrypted using <strong>XSalsa20-Poly1305</strong> (libsodium) with the team's DEK — a different nonce per secret.</li>
        <li>Secrets are decrypted <em>only at execution time</em>, in memory, and are never written to logs or stored in plaintext.</li>
        <li>Every decryption is recorded in the audit log (<code class="rounded bg-gray-100 px-1">audit_entries</code> table) for compliance.</li>
    </ul>
    <x-docs.callout type="tip">
        Rotating the <code>APP_KEY</code> requires re-encrypting team DEKs. Use the
        <code>credentials:re-encrypt</code> Artisan command to batch-migrate all secrets to a new key.
    </x-docs.callout>

    {{-- Statuses --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Credential statuses</h2>
    <div class="mt-3 grid gap-3 sm:grid-cols-4">
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-center">
            <p class="font-semibold text-green-800">Active</p>
            <p class="mt-1 text-xs text-green-700">Credential is valid and will be injected at execution time.</p>
        </div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 p-3 text-center">
            <p class="font-semibold text-gray-700">Disabled</p>
            <p class="mt-1 text-xs text-gray-600">Manually disabled. Will not be injected until re-enabled.</p>
        </div>
        <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-center">
            <p class="font-semibold text-amber-800">Expired</p>
            <p class="mt-1 text-xs text-amber-700">Past the <code>expires_at</code> date. Auto-transitioned by the scheduler.</p>
        </div>
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-center">
            <p class="font-semibold text-red-800">Revoked</p>
            <p class="mt-1 text-xs text-red-700">Permanently invalidated. Cannot be re-enabled — create a new credential instead.</p>
        </div>
    </div>

    {{-- Creating credentials --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Creating credentials</h2>
    <p class="mt-2 text-sm text-gray-600">
        Navigate to <strong>Credentials → New Credential</strong> in the sidebar, or use the API. Required fields:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li><strong>Name</strong> — human-readable label (e.g., <em>OpenAI Production Key</em>).</li>
        <li><strong>Type</strong> — one of the five credential types above.</li>
        <li><strong>Secret data</strong> — JSON object with the actual secret values (see example below).</li>
        <li><strong>Description</strong> (optional) — notes about the credential's purpose or scope.</li>
        <li><strong>Expires at</strong> (optional) — ISO 8601 date. FleetQ auto-expires the credential when this date passes.</li>
    </ul>

    <x-docs.code lang="json" title="secret_data examples by type">
// api_key
{ "key": "sk-abc123..." }

// oauth2
{
  "client_id": "my-app",
  "client_secret": "secret",
  "access_token": "ya29.abc...",
  "refresh_token": "1//0g..."
}

// basic_auth
{ "username": "admin", "password": "hunter2" }

// bearer_token
{ "token": "eyJhbGci..." }

// custom
{ "account_id": "ACC-9001", "api_secret": "xyz", "region": "us-east-1" }</x-docs.code>

    <x-docs.callout type="warning">
        Never paste secrets into the <strong>Name</strong> or <strong>Description</strong> fields — those
        are stored in plaintext. Always use <code>secret_data</code> for sensitive values.
    </x-docs.callout>

    {{-- Secret rotation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Secret rotation</h2>
    <p class="mt-2 text-sm text-gray-600">
        Use <strong>Rotate Secret</strong> from the credential detail page (or <code>POST /api/v1/credentials/{id}/rotate</code>)
        to update the secret without deleting and recreating the credential. The <code>RotateCredentialSecretAction</code>:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li>Atomically replaces <code>secret_data</code> with the new value.</li>
        <li>Re-encrypts under the team's current DEK.</li>
        <li>Logs the rotation to the audit trail (old value is never stored).</li>
        <li>Preserves the credential's UUID — existing agent/skill references remain valid.</li>
    </ul>

    <x-docs.code lang="bash" title="Rotate via API">
curl -X POST https://your-instance.com/api/v1/credentials/cred-uuid-here/rotate \
  -H "Authorization: Bearer $API_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{ "secret_data": { "key": "sk-new-key-here" } }'</x-docs.code>

    {{-- Project credentials --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">Project credentials</h2>
    <p class="mt-2 text-sm text-gray-600">
        Credentials can be scoped to specific projects. The <code>ResolveProjectCredentialsAction</code>
        collects all credentials associated with a project and injects them into agent executions as named
        environment variables. This allows different projects to use different API keys for the same
        service without modifying agent configuration.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        Attach credentials to a project from the project detail page under the <strong>Credentials</strong> tab.
        At execution time, FleetQ decrypts and injects only the credentials assigned to that project — other
        team credentials remain inaccessible.
    </p>

    {{-- BYOK --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">BYOK — Bring Your Own LLM Keys</h2>
    <p class="mt-2 text-sm text-gray-600">
        Teams can supply their own LLM provider API keys instead of relying on platform defaults. Configure
        BYOK keys in <strong>Team Settings → Provider Credentials</strong>.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        The <code>ProviderResolver</code> selects the active LLM key using the following priority hierarchy:
    </p>
    <div class="mt-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:gap-0">
        <div class="rounded-lg border border-primary-200 bg-primary-50 px-4 py-2 text-center text-sm font-semibold text-primary-800">Skill</div>
        <div class="hidden text-gray-400 sm:block sm:px-2">→</div>
        <div class="rounded-lg border border-primary-200 bg-primary-50 px-4 py-2 text-center text-sm font-semibold text-primary-800">Agent</div>
        <div class="hidden text-gray-400 sm:block sm:px-2">→</div>
        <div class="rounded-lg border border-primary-200 bg-primary-50 px-4 py-2 text-center text-sm font-semibold text-primary-800">Team BYOK</div>
        <div class="hidden text-gray-400 sm:block sm:px-2">→</div>
        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-2 text-center text-sm font-semibold text-gray-700">Platform Default</div>
    </div>
    <ul class="mt-3 list-disc pl-5 text-sm text-gray-600">
        <li>A <strong>skill-level</strong> override takes highest precedence (set per skill version).</li>
        <li>An <strong>agent-level</strong> override applies to all skills executed by that agent.</li>
        <li><strong>Team BYOK keys</strong> apply across the team when no skill/agent override is set.</li>
        <li>The <strong>platform default</strong> (configured via <code>.env</code>) is used as the fallback.</li>
    </ul>
    <x-docs.callout type="tip">
        BYOK keys are stored as <code>TeamProviderCredential</code> records, encrypted with the same
        per-team XSalsa20-Poly1305 scheme as regular credentials.
    </x-docs.callout>

    {{-- MCP tools --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">MCP tools</h2>
    <p class="mt-2 text-sm text-gray-600">
        The FleetQ MCP server exposes the following tools for credential management — accessible to any
        LLM agent with a valid session.
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
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">credential_list</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List all credentials for the team with optional status filter.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">credential_get</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Retrieve credential metadata by ID (secret values are never returned).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">credential_create</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new credential with name, type, secret_data, and optional expiry.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">credential_update</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update credential metadata (name, description, expires_at, status).</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">credential_rotate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Replace the secret_data atomically. Preserves all references to the credential.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">credential_oauth_initiate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Begin an OAuth2 authorization code flow — returns the redirect URL.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-gray-900">credential_oauth_finalize</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Exchange the OAuth2 callback code for tokens and store them encrypted.</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- API endpoints --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">API endpoints</h2>
    <p class="mt-2 text-sm text-gray-600">
        All endpoints require a Sanctum bearer token and respect team scope. Full OpenAPI schema available at
        <code class="rounded bg-gray-100 px-1 text-xs">/docs/api</code>.
    </p>
    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-6 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-6 text-left font-semibold text-gray-700">Path</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Description</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-indigo-700">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/credentials</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">List credentials. Filter by <code class="rounded bg-gray-100 px-1">status</code> or <code class="rounded bg-gray-100 px-1">type</code>. Cursor-paginated.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-indigo-700">GET</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/credentials/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Get a single credential. Secret values are omitted from the response.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-green-700">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/credentials</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Create a new credential. Pass <code class="rounded bg-gray-100 px-1">name</code>, <code class="rounded bg-gray-100 px-1">type</code>, <code class="rounded bg-gray-100 px-1">secret_data</code>, and optionally <code class="rounded bg-gray-100 px-1">expires_at</code>.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-amber-700">PUT</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/credentials/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Update credential metadata. Use <code class="rounded bg-gray-100 px-1">/rotate</code> to change secrets.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-red-700">DELETE</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/credentials/{id}</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Delete a credential. This is permanent and cannot be undone.</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-6 font-mono text-xs font-medium text-green-700">POST</td>
                    <td class="py-2.5 pr-6 font-mono text-xs text-gray-900">/api/v1/credentials/{id}/rotate</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Replace secret values atomically. Pass the full new <code class="rounded bg-gray-100 px-1">secret_data</code> object.</td>
                </tr>
            </tbody>
        </table>
    </div>

    <x-docs.callout type="warning">
        Deleting a credential that is actively referenced by an agent or project will cause execution failures.
        Prefer setting the status to <strong>disabled</strong> while you migrate references, then delete.
    </x-docs.callout>
</x-layouts.docs>
