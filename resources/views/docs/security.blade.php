<x-layouts.docs
    title="Security"
    description="FleetQ security deep-dive: authentication, per-team encryption, KMS, PostgreSQL RLS, tenant isolation, rate limiting, circuit breakers, SSRF protection, and the immutable audit trail."
    page="security"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Security &amp; Data Protection</h1>
    <p class="mt-4 text-gray-600">
        FleetQ is built with a <strong>defense-in-depth</strong> security model. Multiple independent layers
        protect your data, credentials, and integrations — so that no single layer failure compromises the system.
        This page documents every protection running behind the scenes.
    </p>

    {{-- Table of contents --}}
    <div class="mt-6 rounded-xl border border-gray-200 bg-gray-50 p-5">
        <p class="text-sm font-semibold text-gray-700">On this page</p>
        <div class="mt-3 grid gap-x-6 gap-y-1 sm:grid-cols-2 text-sm text-gray-600">
            <a href="#authentication" class="hover:text-primary-600">1. Authentication (5 methods)</a>
            <a href="#encryption" class="hover:text-primary-600">2. Credential Encryption</a>
            <a href="#kms" class="hover:text-primary-600">3. External KMS Integration</a>
            <a href="#tenant-isolation" class="hover:text-primary-600">4. Tenant Isolation (4 layers)</a>
            <a href="#rls" class="hover:text-primary-600">5. PostgreSQL Row-Level Security</a>
            <a href="#authorization" class="hover:text-primary-600">6. Authorization &amp; Roles</a>
            <a href="#budget" class="hover:text-primary-600">7. Budget Enforcement</a>
            <a href="#rate-limiting" class="hover:text-primary-600">8. Rate Limiting (5 layers)</a>
            <a href="#ai-gateway" class="hover:text-primary-600">9. AI Gateway Security</a>
            <a href="#ssrf" class="hover:text-primary-600">10. SSRF Protection</a>
            <a href="#webhooks" class="hover:text-primary-600">11. Webhook Security</a>
            <a href="#headers" class="hover:text-primary-600">12. Security Headers &amp; CSP</a>
            <a href="#credentials" class="hover:text-primary-600">13. Credential Lifecycle</a>
            <a href="#audit" class="hover:text-primary-600">14. Immutable Audit Log</a>
        </div>
    </div>

    {{-- ================================================================== --}}
    {{-- 1. Authentication --}}
    {{-- ================================================================== --}}
    <h2 id="authentication" class="mt-12 text-xl font-bold text-gray-900">1. Authentication</h2>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ supports five authentication methods, each designed for a different access pattern.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-4 text-left font-semibold text-gray-700">Method</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Mechanism</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Expiry</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Use case</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Session (Web)</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Laravel Fortify + Redis sessions</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Session TTL</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Browser access</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Two-Factor (TOTP)</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Authenticator app + recovery codes</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Per-session</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Hardened web login</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">API Token (Sanctum)</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Bearer token, <code class="rounded bg-gray-100 px-1 text-xs">af_</code> prefix</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">30 days</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">REST API, mobile, CLI</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">OAuth2 (Passport)</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Authorization Code + PKCE</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">24h access / 30d refresh</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">MCP clients (Cursor, Claude.ai)</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Webhook HMAC</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">HMAC-SHA256 signature verification</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Per-request</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Signal ingestion, integrations</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- 1a. 2FA --}}
    <h3 class="mt-6 text-base font-semibold text-gray-900">Two-Factor Authentication (2FA)</h3>
    <p class="mt-2 text-sm text-gray-600">
        FleetQ supports <strong>TOTP-based two-factor authentication</strong> via authenticator apps
        (Google Authenticator, Authy, 1Password, etc.). Enable it from your profile settings.
    </p>
    <div class="mt-3 space-y-2">
        <x-docs.step number="1" title="Enable 2FA">Scan the QR code with your authenticator app.</x-docs.step>
        <x-docs.step number="2" title="Confirm">Enter the 6-digit code from your authenticator to activate. 2FA is not active until confirmed.</x-docs.step>
        <x-docs.step number="3" title="Save recovery codes">Download or copy the auto-generated recovery codes. These are single-use codes in case you lose your authenticator device.</x-docs.step>
    </div>
    <p class="mt-3 text-sm text-gray-600">
        The 2FA secret is encrypted with <code class="rounded bg-gray-100 px-1 text-xs">APP_KEY</code> and never stored in plaintext.
        Recovery codes can be regenerated at any time from your profile. 2FA challenges are throttled to <strong>5 attempts per minute</strong>.
    </p>

    {{-- 1b. API tokens --}}
    <h3 class="mt-6 text-base font-semibold text-gray-900">API Token Scoping</h3>
    <p class="mt-2 text-sm text-gray-600">
        Sanctum tokens are issued with <code class="rounded bg-gray-100 px-1 text-xs">team:{team_id}</code> abilities — never the wildcard
        <code class="rounded bg-gray-100 px-1 text-xs">*</code>. The <code class="rounded bg-gray-100 px-1 text-xs">ScopeTokenToTeam</code> middleware
        rejects any request where the token's team claim doesn't match the target resource.
        Token prefix <code class="rounded bg-gray-100 px-1 text-xs">af_</code> allows secret-scanning tools to identify leaked tokens.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        Manage active tokens from <strong>Team Settings &rarr; API Tokens</strong> or via
        <code class="rounded bg-gray-100 px-1 text-xs">GET /api/v1/auth/devices</code>. Revoke individually or all at once.
    </p>

    {{-- 1c. OAuth2 / MCP --}}
    <h3 class="mt-6 text-base font-semibold text-gray-900">OAuth2 for MCP Clients</h3>
    <p class="mt-2 text-sm text-gray-600">
        The MCP server uses <strong>OAuth 2.1 with PKCE</strong> for HTTP/SSE clients (Cursor, Claude.ai, etc.).
        The platform publishes standard discovery endpoints:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li><code class="rounded bg-gray-100 px-1 text-xs">/.well-known/oauth-authorization-server</code> — RFC 8414 server metadata</li>
        <li><code class="rounded bg-gray-100 px-1 text-xs">/.well-known/oauth-protected-resource</code> — RFC 9728 resource metadata</li>
        <li><code class="rounded bg-gray-100 px-1 text-xs">POST /oauth/register</code> — RFC 7591 dynamic client registration (20/hour limit)</li>
    </ul>
    <p class="mt-2 text-sm text-gray-600">
        Local MCP connections (stdio) auto-authenticate as the default team owner — no token exchange needed.
    </p>

    {{-- 1d. Chatbot tokens --}}
    <h3 class="mt-6 text-base font-semibold text-gray-900">Chatbot Token Authentication</h3>
    <p class="mt-2 text-sm text-gray-600">
        Chatbot embeds use dedicated tokens stored as <strong>SHA256 hashes</strong> (plaintext never stored).
        Each token supports optional <strong>origin validation</strong> (allowlisted domains), expiry dates, and automatic
        <code class="rounded bg-gray-100 px-1 text-xs">last_used_at</code> tracking.
    </p>

    {{-- 1e. Login throttling --}}
    <h3 class="mt-6 text-base font-semibold text-gray-900">Login Throttling</h3>
    <div class="mt-2 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-2.5 pl-4 pr-4 text-left font-semibold text-gray-700">Endpoint</th>
                    <th class="py-2.5 pr-4 text-left font-semibold text-gray-700">Limit</th>
                    <th class="py-2.5 pr-4 text-left font-semibold text-gray-700">Key</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2 pl-4 pr-4 text-xs text-gray-900">Login</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">5/min</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">email + IP</td>
                </tr>
                <tr>
                    <td class="py-2 pl-4 pr-4 text-xs text-gray-900">2FA challenge</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">5/min</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">session ID</td>
                </tr>
                <tr>
                    <td class="py-2 pl-4 pr-4 text-xs text-gray-900">Password reset request</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">3/min</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">email + IP</td>
                </tr>
                <tr>
                    <td class="py-2 pl-4 pr-4 text-xs text-gray-900">Token refresh</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">10/min</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">IP</td>
                </tr>
                <tr>
                    <td class="py-2 pl-4 pr-4 text-xs text-gray-900">OAuth client registration</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">20/hour</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">IP</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- ================================================================== --}}
    {{-- 2. Credential Encryption --}}
    {{-- ================================================================== --}}
    <h2 id="encryption" class="mt-12 text-xl font-bold text-gray-900">2. Credential Encryption</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every API key, OAuth token, and bearer credential is encrypted using <strong>per-team envelope encryption</strong>
        with two independent layers:
    </p>

    <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 p-5">
        <p class="text-sm font-semibold text-gray-900">2-Layer Encryption Architecture</p>
        <div class="mt-3 flex flex-col items-start gap-2 text-sm text-gray-600">
            <div class="flex items-center gap-2">
                <span class="rounded bg-blue-100 px-2 py-0.5 font-mono text-xs font-medium text-blue-700">Layer 1</span>
                <span>Team DEK (32-byte key) encrypted with platform <code class="rounded bg-gray-100 px-1 text-xs">APP_KEY</code></span>
            </div>
            <div class="flex items-center gap-2">
                <span class="rounded bg-blue-100 px-2 py-0.5 font-mono text-xs font-medium text-blue-700">Layer 2</span>
                <span>Credential data encrypted with Team DEK using <strong>XSalsa20-Poly1305</strong> (libsodium)</span>
            </div>
        </div>
        <p class="mt-3 text-xs text-gray-500">
            Storage format: <code class="rounded bg-gray-100 px-1 text-xs">base64(JSON{v:2, n:nonce, c:ciphertext})</code>
        </p>
    </div>

    <x-docs.callout type="info">
        Even a full database breach cannot expose raw credentials without the APP_KEY.
        The per-team DEK adds a second layer: compromising one team's key doesn't affect others.
    </x-docs.callout>

    <p class="mt-3 text-sm text-gray-600">
        <strong>Encrypted across 7 columns in 5 models:</strong> Credential secrets, team provider credentials (BYOK),
        tool credentials, outbound connector configs, Telegram bot tokens, and webhook signing secrets.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Backward Compatibility</h3>
    <p class="mt-2 text-sm text-gray-600">
        Three encryption formats are supported transparently:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li><strong>v2</strong> (current): Team DEK + XSalsa20-Poly1305</li>
        <li><strong>v1</strong>: Laravel's standard <code class="rounded bg-gray-100 px-1 text-xs">encrypt()</code> with APP_KEY</li>
        <li><strong>v0</strong>: Legacy PHP-serialized format</li>
    </ul>
    <p class="mt-2 text-sm text-gray-600">
        Decryption auto-detects the format. New writes always use v2. Batch-migrate with:
    </p>
    <x-docs.code lang="bash">php artisan credentials:re-encrypt --batch=50
# Add --dry-run to preview without changes</x-docs.code>

    {{-- ================================================================== --}}
    {{-- 3. KMS --}}
    {{-- ================================================================== --}}
    <h2 id="kms" class="mt-12 text-xl font-bold text-gray-900">3. External KMS Integration</h2>
    <p class="mt-2 text-sm text-gray-600">
        For enterprise deployments, the team DEK can be wrapped by an <strong>external Key Management Service</strong>
        instead of the platform APP_KEY. This means revoking KMS access immediately revokes all credential access —
        there is no APP_KEY fallback when KMS is active.
    </p>

    <div class="mt-4 grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">AWS KMS</p>
            <p class="mt-1 text-xs text-gray-600">Key ARN + optional AssumeRole with external ID for cross-account access.</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Azure Key Vault</p>
            <p class="mt-1 text-xs text-gray-600">Vault URL + client credentials (tenant ID, client ID).</p>
        </div>
        <div class="rounded-xl border border-gray-200 p-4">
            <p class="font-semibold text-gray-900">Google Cloud KMS</p>
            <p class="mt-1 text-xs text-gray-600">Project, location, key ring, and key name.</p>
        </div>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        Unwrapped DEKs are cached in a <strong>3-layer cache</strong> (in-memory &rarr; Redis 5min &rarr; KMS API call)
        to minimize latency. KMS unwrap failures are logged to the audit trail and set the config status to <strong>Error</strong>.
    </p>

    <x-docs.callout type="warning">
        KMS credentials are encrypted with APP_KEY (not the team DEK) to avoid a circular dependency —
        you need KMS to unwrap the DEK, but you'd need the DEK to decrypt KMS credentials.
    </x-docs.callout>

    {{-- ================================================================== --}}
    {{-- 4. Tenant Isolation --}}
    {{-- ================================================================== --}}
    <h2 id="tenant-isolation" class="mt-12 text-xl font-bold text-gray-900">4. Tenant Isolation</h2>
    <p class="mt-2 text-sm text-gray-600">
        Data isolation is enforced through <strong>four independent layers</strong>. A bug in any single layer cannot
        expose cross-tenant data because the other three layers independently enforce boundaries.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-4 text-left font-semibold text-gray-700">Layer</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Mechanism</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Scope</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Application</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        <code class="rounded bg-gray-100 px-1 text-xs">TeamScope</code> global scope + <code class="rounded bg-gray-100 px-1 text-xs">BelongsToTeam</code> trait auto-apply
                        <code class="rounded bg-gray-100 px-1 text-xs">WHERE team_id = ?</code> to every Eloquent query
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">All models</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Database</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        PostgreSQL <strong>Row-Level Security</strong> policies enforce team_id filtering at the database engine level
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">All team-scoped tables</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">MCP Tools</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        All 268+ tools use explicit <code class="rounded bg-gray-100 px-1 text-xs">where('team_id', $teamId)</code> guards
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">MCP server</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Encryption</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">
                        Per-team DEK means even raw database access can't decrypt another team's credentials
                    </td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">All encrypted fields</td>
                </tr>
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        <strong>Platform records</strong> (<code class="rounded bg-gray-100 px-1 text-xs">team_id = NULL</code>) are shared reference data
        visible to all teams but <strong>read-only</strong> — the <code class="rounded bg-gray-100 px-1 text-xs">PlatformRecordGuardObserver</code>
        returns 403 on any update or delete attempt.
    </p>

    {{-- ================================================================== --}}
    {{-- 5. PostgreSQL RLS --}}
    {{-- ================================================================== --}}
    <h2 id="rls" class="mt-12 text-xl font-bold text-gray-900">5. PostgreSQL Row-Level Security</h2>
    <p class="mt-2 text-sm text-gray-600">
        Beyond the application-level <code class="rounded bg-gray-100 px-1 text-xs">TeamScope</code>, FleetQ uses
        <strong>PostgreSQL's native Row-Level Security (RLS)</strong> as a second enforcement layer directly in the database engine.
        Even if the application layer has a bug that bypasses TeamScope, the database itself prevents cross-tenant queries.
    </p>

    <h3 class="mt-4 text-base font-semibold text-gray-900">How it works</h3>
    <div class="mt-3 space-y-2">
        <x-docs.step number="1" title="Session context">
            Each web request sets a PostgreSQL GUC variable:
            <code class="rounded bg-gray-100 px-1 text-xs">set_config('app.current_team_id', team_id, false)</code>
        </x-docs.step>
        <x-docs.step number="2" title="Role switch">
            The connection switches to a non-superuser role (<code class="rounded bg-gray-100 px-1 text-xs">agent_fleet_rls</code>)
            that is subject to <code class="rounded bg-gray-100 px-1 text-xs">FORCE ROW LEVEL SECURITY</code>.
        </x-docs.step>
        <x-docs.step number="3" title="Policy enforcement">
            RLS policies on every team-scoped table restrict rows to
            <code class="rounded bg-gray-100 px-1 text-xs">WHERE team_id = current_setting('app.current_team_id')</code>.
        </x-docs.step>
        <x-docs.step number="4" title="Reset">
            After the request, <code class="rounded bg-gray-100 px-1 text-xs">RESET ROLE</code> restores the original connection role.
        </x-docs.step>
    </div>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Queue jobs (Horizon)</h3>
    <p class="mt-2 text-sm text-gray-600">
        Horizon workers reuse database connections across jobs, so queue jobs use
        <code class="rounded bg-gray-100 px-1 text-xs">SET LOCAL</code> inside a transaction. This scopes the team context
        to the current transaction only — it automatically resets at <code class="rounded bg-gray-100 px-1 text-xs">COMMIT</code>
        or <code class="rounded bg-gray-100 px-1 text-xs">ROLLBACK</code>, preventing context leak between jobs.
    </p>

    <x-docs.callout type="tip">
        RLS is a <strong>defense-in-depth addition</strong> — it does not replace TeamScope.
        If the <code class="rounded bg-gray-100 px-1 text-xs">agent_fleet_rls</code> role doesn't exist (migration hasn't run),
        the middleware gracefully becomes a no-op.
    </x-docs.callout>

    {{-- ================================================================== --}}
    {{-- 6. Authorization --}}
    {{-- ================================================================== --}}
    <h2 id="authorization" class="mt-12 text-xl font-bold text-gray-900">6. Authorization &amp; Roles</h2>
    <p class="mt-2 text-sm text-gray-600">
        Team members are assigned one of four roles. Permissions are enforced via Laravel gates.
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-4 text-left font-semibold text-gray-700">Role</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Manage team</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Edit content</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">View</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Billing</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach([
                    ['Owner',  'Yes', 'Yes', 'Yes', 'Yes'],
                    ['Admin',  'Yes', 'Yes', 'Yes', 'No'],
                    ['Member', 'No',  'Yes', 'Yes', 'No'],
                    ['Viewer', 'No',  'No',  'Yes', 'No'],
                ] as [$role, $manage, $edit, $view, $billing])
                <tr>
                    <td class="py-2 pl-4 pr-4 font-medium text-gray-900">{{ $role }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $manage }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $edit }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $view }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $billing }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        The AI Assistant's tools are also <strong>role-gated</strong>: read tools (list, get, search) are available to all roles,
        write tools (create, update) require Member+, and destructive tools (delete, toggle status) require Admin or Owner.
    </p>

    {{-- ================================================================== --}}
    {{-- 7. Budget Enforcement --}}
    {{-- ================================================================== --}}
    <h2 id="budget" class="mt-12 text-xl font-bold text-gray-900">7. Budget Enforcement</h2>
    <p class="mt-2 text-sm text-gray-600">
        Budget controls use <strong>pessimistic locking</strong> and <strong>atomic Redis operations</strong>
        to prevent race conditions. No TOCTOU vulnerability is possible.
    </p>

    <div class="mt-4 space-y-2">
        <x-docs.step number="1" title="Reservation (before AI call)">
            <code class="rounded bg-gray-100 px-1 text-xs">ReserveBudgetAction</code> acquires a
            <code class="rounded bg-gray-100 px-1 text-xs">SELECT FOR UPDATE</code> lock on both the experiment and credit ledger,
            then deducts the estimated cost (with 1.5x safety multiplier) within a DB transaction.
        </x-docs.step>
        <x-docs.step number="2" title="Execution">
            The AI call proceeds. If the model call fails, the reservation is released (no charge).
        </x-docs.step>
        <x-docs.step number="3" title="Settlement (after AI call)">
            <code class="rounded bg-gray-100 px-1 text-xs">SettleBudgetAction</code> compares actual vs reserved cost.
            Overage is charged; surplus is refunded — all within a locked transaction.
        </x-docs.step>
        <x-docs.step number="4" title="Circuit breaker">
            <code class="rounded bg-gray-100 px-1 text-xs">PauseOnBudgetExceeded</code> fires on every experiment transition.
            If the budget is exhausted, the experiment is paused before the next job runs.
        </x-docs.step>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        Usage counters for plan limits use <strong>Redis Lua scripts</strong> for atomic check-and-increment — the check
        and increment happen in a single Redis operation, preventing any race between concurrent requests.
    </p>

    {{-- ================================================================== --}}
    {{-- 8. Rate Limiting --}}
    {{-- ================================================================== --}}
    <h2 id="rate-limiting" class="mt-12 text-xl font-bold text-gray-900">8. Rate Limiting (5 Layers)</h2>
    <p class="mt-2 text-sm text-gray-600">
        Five independent rate-limiting layers protect different parts of the system:
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-4 text-left font-semibold text-gray-700">Layer</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Scope</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Default limit</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Mechanism</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">HTTP</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Per route</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">5&ndash;120/min</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Laravel throttle middleware</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">AI Provider</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Per provider</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">30&ndash;100/min</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">AI middleware pipeline</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Queue</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Per team &times; queue</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">60/min</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Redis sorted set sliding window</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Outbound channel</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Per channel &times; experiment</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">10&ndash;50/window</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Configurable per channel</td>
                </tr>
                <tr>
                    <td class="py-2.5 pl-4 pr-4 font-medium text-gray-900">Outbound target</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Per recipient</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">7-day cooldown</td>
                    <td class="py-2.5 pr-4 text-xs text-gray-600">Prevents duplicate outreach</td>
                </tr>
            </tbody>
        </table>
    </div>

    {{-- ================================================================== --}}
    {{-- 9. AI Gateway Security --}}
    {{-- ================================================================== --}}
    <h2 id="ai-gateway" class="mt-12 text-xl font-bold text-gray-900">9. AI Gateway Security</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every LLM call passes through a <strong>6-stage middleware pipeline</strong>:
    </p>

    <div class="mt-3 flex flex-wrap items-center gap-2 text-sm text-gray-600">
        @foreach(['RateLimiting', 'BudgetEnforcement', 'IdempotencyCheck', 'SemanticCache', 'SchemaValidation', 'UsageTracking'] as $mw)
        <span class="rounded bg-gray-100 px-2 py-0.5 font-mono text-xs">{{ $mw }}</span>
        @if(!$loop->last)<span class="text-gray-400">&rarr;</span>@endif
        @endforeach
    </div>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Circuit Breaker</h3>
    <p class="mt-2 text-sm text-gray-600">
        A per-provider circuit breaker protects against cascading failures from external LLM APIs.
        Three states: <strong>Closed</strong> (healthy) &rarr; <strong>Open</strong> (after 5 failures, all calls rejected)
        &rarr; <strong>Half-Open</strong> (after 60s, probe request sent) &rarr; back to Closed on success.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Idempotency (Deduplication)</h3>
    <p class="mt-2 text-sm text-gray-600">
        Duplicate LLM requests are detected via <code class="rounded bg-gray-100 px-1 text-xs">xxh128</code> hash of the
        system prompt + user prompt. Identical requests return the cached response at <strong>zero cost</strong>.
        Pending or failed requests are retried automatically.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Semantic Cache</h3>
    <p class="mt-2 text-sm text-gray-600">
        Near-duplicate prompts are matched via <strong>pgvector cosine similarity</strong> (threshold 0.92).
        Only the normalised prompt text is stored — never raw credentials, PII, or personally identifiable data.
        Cache lookups are team-scoped via explicit <code class="rounded bg-gray-100 px-1 text-xs">where('team_id', ...)</code>.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Kill Switch</h3>
    <p class="mt-2 text-sm text-gray-600">
        The <code class="rounded bg-gray-100 px-1 text-xs">CheckKillSwitch</code> job middleware runs on every pipeline stage job.
        <strong>Killed</strong> experiments: job silently skipped. <strong>Paused</strong> experiments: job released
        back to the queue with a 60-second delay, automatically resuming when un-paused.
    </p>

    {{-- ================================================================== --}}
    {{-- 10. SSRF Protection --}}
    {{-- ================================================================== --}}
    <h2 id="ssrf" class="mt-12 text-xl font-bold text-gray-900">10. SSRF Protection</h2>
    <p class="mt-2 text-sm text-gray-600">
        <code class="rounded bg-gray-100 px-1 text-xs">SsrfGuard</code> validates all outbound URLs
        (webhooks, RSS feeds, OAuth callbacks, SMTP hosts) against private address ranges:
    </p>
    <div class="mt-3 grid gap-2 sm:grid-cols-3">
        @foreach([
            ['IPv4 Private', '10.0.0.0/8, 172.16.0.0/12, 192.168.0.0/16'],
            ['Loopback', '127.0.0.0/8, ::1'],
            ['Link-local / Metadata', '169.254.0.0/16, 100.64.0.0/10'],
            ['IPv6 Private', 'fc00::/7 (unique local), fe80::/10 (link-local)'],
        ] as [$label, $cidrs])
        <div class="rounded-lg border border-gray-200 p-3">
            <p class="text-xs font-semibold text-gray-900">{{ $label }}</p>
            <p class="mt-1 font-mono text-xs text-gray-500">{{ $cidrs }}</p>
        </div>
        @endforeach
    </div>
    <p class="mt-3 text-sm text-gray-600">
        An attempt to configure a webhook or connector pointing at an internal IP returns <strong>422 Validation Error</strong>.
    </p>

    <h3 class="mt-4 text-base font-semibold text-gray-900">Outbound Header Allowlist</h3>
    <p class="mt-2 text-sm text-gray-600">
        Outbound webhook connectors only forward headers matching <code class="rounded bg-gray-100 px-1 text-xs">x-*</code>
        and <code class="rounded bg-gray-100 px-1 text-xs">content-type</code>.
        Headers like <code class="rounded bg-gray-100 px-1 text-xs">Authorization</code>,
        <code class="rounded bg-gray-100 px-1 text-xs">Host</code>, and <code class="rounded bg-gray-100 px-1 text-xs">Cookie</code>
        are stripped — preventing header injection attacks that could proxy authentication credentials.
    </p>

    <h3 class="mt-4 text-base font-semibold text-gray-900">SMTP Host Validation</h3>
    <p class="mt-2 text-sm text-gray-600">
        Custom SMTP servers are validated against the same CIDR blocklist.
        Email recipient addresses are validated via <code class="rounded bg-gray-100 px-1 text-xs">FILTER_VALIDATE_EMAIL</code>,
        and from-address headers are sanitised to prevent header injection
        (<code class="rounded bg-gray-100 px-1 text-xs">\r</code>, <code class="rounded bg-gray-100 px-1 text-xs">\n</code>, <code class="rounded bg-gray-100 px-1 text-xs">&lt;</code>, <code class="rounded bg-gray-100 px-1 text-xs">&gt;</code> stripped).
    </p>

    <h3 class="mt-4 text-base font-semibold text-gray-900">Outbound Blacklist</h3>
    <p class="mt-2 text-sm text-gray-600">
        Before every outbound delivery, a 4-level blacklist check runs: email exact match, domain match,
        company name (case-insensitive), and keyword substring search. Blacklisted deliveries are blocked
        with a logged reason.
    </p>

    {{-- ================================================================== --}}
    {{-- 11. Webhook Security --}}
    {{-- ================================================================== --}}
    <h2 id="webhooks" class="mt-12 text-xl font-bold text-gray-900">11. Webhook Security</h2>

    <h3 class="mt-4 text-base font-semibold text-gray-900">Inbound Webhook Verification</h3>
    <p class="mt-2 text-sm text-gray-600">
        Signal webhooks require <strong>HMAC-SHA256</strong> signature verification via the
        <code class="rounded bg-gray-100 px-1 text-xs">X-Webhook-Signature</code> header. Signatures are compared using
        constant-time <code class="rounded bg-gray-100 px-1 text-xs">hash_equals()</code> to prevent timing attacks.
        In production, unsigned requests are rejected with 403 (fail-closed).
    </p>

    <h3 class="mt-4 text-base font-semibold text-gray-900">Integration-Specific Verification</h3>
    <p class="mt-2 text-sm text-gray-600">
        Each integration connector has its own signature verification method:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li><strong>GitHub</strong>: HMAC-SHA256</li>
        <li><strong>Slack</strong>: HMAC-SHA256 (Events API)</li>
        <li><strong>Discord</strong>: Ed25519 signature</li>
        <li><strong>Stripe</strong>: Stripe signature verification</li>
        <li><strong>Jira, Linear, Zendesk, PagerDuty</strong>: Per-provider HMAC</li>
    </ul>

    <h3 class="mt-4 text-base font-semibold text-gray-900">Replay Protection</h3>
    <p class="mt-2 text-sm text-gray-600">
        Inbound webhooks include an <code class="rounded bg-gray-100 px-1 text-xs">X-Webhook-Timestamp</code> header.
        Requests older than <strong>5 minutes</strong> are rejected — preventing replay attacks.
        Outbound webhooks use idempotency keys for deduplication and HMAC-SHA256 signing.
    </p>

    {{-- ================================================================== --}}
    {{-- 12. Security Headers --}}
    {{-- ================================================================== --}}
    <h2 id="headers" class="mt-12 text-xl font-bold text-gray-900">12. Security Headers &amp; CSP</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <code class="rounded bg-gray-100 px-1 text-xs">SecurityHeaders</code> middleware sets protective headers on every response:
    </p>

    <div class="mt-4 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-3 pl-4 pr-4 text-left font-semibold text-gray-700">Header</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Value</th>
                    <th class="py-3 pr-4 text-left font-semibold text-gray-700">Protection</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach([
                    ['X-Content-Type-Options', 'nosniff', 'Prevents MIME-type sniffing'],
                    ['X-Frame-Options', 'SAMEORIGIN', 'Clickjacking protection'],
                    ['Referrer-Policy', 'strict-origin-when-cross-origin', 'Limits referrer leakage'],
                    ['Permissions-Policy', 'camera=(), microphone=(), geolocation=()', 'Restricts browser APIs'],
                    ['Strict-Transport-Security', 'max-age=31536000', 'HSTS (production only)'],
                ] as [$header, $value, $protection])
                <tr>
                    <td class="py-2 pl-4 pr-4 font-mono text-xs text-gray-900">{{ $header }}</td>
                    <td class="py-2 pr-4 font-mono text-xs text-gray-600">{{ $value }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $protection }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <p class="mt-3 text-sm text-gray-600">
        <strong>Content Security Policy:</strong> <code class="rounded bg-gray-100 px-1 text-xs">default-src 'self'</code>,
        scripts from allowlisted CDNs only, <code class="rounded bg-gray-100 px-1 text-xs">form-action 'self'</code>,
        <code class="rounded bg-gray-100 px-1 text-xs">object-src 'none'</code>,
        <code class="rounded bg-gray-100 px-1 text-xs">base-uri 'self'</code>.
        CSRF is enforced on all web routes via Laravel's built-in middleware.
    </p>

    {{-- ================================================================== --}}
    {{-- 13. Credential Lifecycle --}}
    {{-- ================================================================== --}}
    <h2 id="credentials" class="mt-12 text-xl font-bold text-gray-900">13. Credential Lifecycle</h2>

    <h3 class="mt-4 text-base font-semibold text-gray-900">Types</h3>
    <div class="mt-2 overflow-hidden rounded-xl border border-gray-200">
        <table class="w-full text-sm">
            <thead>
                <tr class="border-b border-gray-200 bg-gray-50">
                    <th class="py-2.5 pl-4 pr-4 text-left font-semibold text-gray-700">Type</th>
                    <th class="py-2.5 pr-4 text-left font-semibold text-gray-700">Fields</th>
                    <th class="py-2.5 pr-4 text-left font-semibold text-gray-700">Use case</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                @foreach([
                    ['api_key', 'token', 'External API services'],
                    ['oauth2', 'access_token, refresh_token', 'OAuth2 integrations'],
                    ['basic_auth', 'username, password', 'Legacy HTTP auth'],
                    ['ssh_key', 'private_key, passphrase', 'SSH/Git operations'],
                    ['custom', 'Flexible key-value', 'Custom credential schemas'],
                ] as [$type, $fields, $use])
                <tr>
                    <td class="py-2 pl-4 pr-4 font-mono text-xs text-gray-900">{{ $type }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $fields }}</td>
                    <td class="py-2 pr-4 text-xs text-gray-600">{{ $use }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Human vs AI-Created Credentials</h3>
    <p class="mt-2 text-sm text-gray-600">
        Credentials created by a human are <strong>Active immediately</strong>.
        Credentials created by AI agents enter <strong>PendingReview</strong> status and require
        human approval before they can be used — preventing AI agents from autonomously creating
        and using credentials without oversight.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">Secret Rotation</h3>
    <p class="mt-2 text-sm text-gray-600">
        Rotate any credential's secrets via the detail page or
        <code class="rounded bg-gray-100 px-1 text-xs">POST /api/v1/credentials/{id}/rotate</code>.
        Rotation re-encrypts with the current team DEK and updates the
        <code class="rounded bg-gray-100 px-1 text-xs">last_rotated_at</code> timestamp.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">BYOK (Bring Your Own Key)</h3>
    <p class="mt-2 text-sm text-gray-600">
        Teams configure their own LLM provider API keys (Anthropic, OpenAI, Google, custom endpoints)
        via Team Settings. Keys are encrypted with the team DEK and masked in the UI
        (<code class="rounded bg-gray-100 px-1 text-xs">****</code> + last 4 chars).
        The provider resolver injects credentials at runtime: Skill &rarr; Agent &rarr; Team &rarr; Platform default.
    </p>

    <h3 class="mt-6 text-base font-semibold text-gray-900">SSH Fingerprint Verification (TOFU)</h3>
    <p class="mt-2 text-sm text-gray-600">
        SSH connections use <strong>Trust On First Use</strong>: the first connection stores the host's
        SHA256 fingerprint. Subsequent connections verify the fingerprint matches — a mismatch
        (possible MITM attack) throws an error and blocks the connection.
    </p>

    {{-- ================================================================== --}}
    {{-- 14. Audit Log --}}
    {{-- ================================================================== --}}
    <h2 id="audit" class="mt-12 text-xl font-bold text-gray-900">14. Immutable Audit Log</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every significant action is recorded in an <strong>append-only</strong>
        <code class="rounded bg-gray-100 px-1 text-xs">audit_entries</code> table. Entries are never updated or deleted
        within the retention window.
    </p>
    <div class="mt-4 grid gap-3 sm:grid-cols-2">
        @foreach([
            ['Experiment transitions', 'Every state change — who triggered it, when, and why'],
            ['Approval decisions', 'Approve/reject with reviewer identity and decision context'],
            ['Budget events', 'Reservations, settlements, alerts, and exhaustion events'],
            ['Agent events', 'Health check failures, status changes, execution starts'],
            ['Credential access', 'Every decryption — requesting agent, experiment, and purpose'],
            ['Team changes', 'Invitations, role changes, token creation/revocation'],
        ] as [$event, $desc])
        <div class="rounded-lg border border-gray-200 p-4">
            <p class="text-sm font-semibold text-gray-900">{{ $event }}</p>
            <p class="mt-1 text-xs text-gray-600">{{ $desc }}</p>
        </div>
        @endforeach
    </div>
    <p class="mt-3 text-sm text-gray-600">
        Retention is enforced by your plan. See
        <a href="{{ route('docs.show', 'audit-log') }}" class="text-primary-600 hover:underline">Audit Log</a>
        for details on retention periods, compliance use cases, and API access.
    </p>

    {{-- ================================================================== --}}
    {{-- Summary --}}
    {{-- ================================================================== --}}
    <div class="mt-10 rounded-xl border border-primary-200 bg-primary-50 px-6 py-5">
        <p class="font-semibold text-primary-900">Defense in Depth</p>
        <p class="mt-1 text-sm text-primary-700">
            FleetQ's security model ensures that no single layer failure compromises the system.
            Application-level scoping, database RLS, per-team encryption, and MCP tool guards all
            enforce boundaries independently — so a bug in one layer is caught by the others.
        </p>
    </div>

    <x-docs.callout type="tip">
        See also: <a href="{{ route('docs.show', 'credentials') }}" class="font-medium underline">Credentials</a> (managing secrets),
        <a href="{{ route('docs.show', 'audit-log') }}" class="font-medium underline">Audit Log</a> (querying the audit trail),
        <a href="{{ route('docs.show', 'budget') }}" class="font-medium underline">Budget &amp; Cost</a> (spending controls).
    </x-docs.callout>
</x-layouts.docs>
