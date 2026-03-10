<x-layouts.docs
    title="Security"
    description="FleetQ security deep-dive: credential encryption, tenant isolation, token scoping, SSRF protection, rate limiting, and the audit trail."
    page="security"
>
    <h1 class="text-3xl font-bold tracking-tight text-gray-900">Security &amp; Data Protection</h1>
    <p class="mt-4 text-gray-600">
        FleetQ is built with a defense-in-depth security model. Most protections are invisible during normal
        use — this page documents what's running behind the scenes to keep your data and integrations secure.
    </p>

    {{-- 1. Credential encryption --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">1. Credential Encryption</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every API key, OAuth token, and bearer credential is encrypted before storage using
        <strong>XSalsa20-Poly1305</strong> (libsodium). The encryption uses a <strong>per-team data encryption key (DEK)</strong>
        — a 32-byte key auto-generated for each team, itself encrypted with the platform's master APP_KEY.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        Format: <code class="rounded bg-gray-100 px-1 text-xs">v2:base64(JSON{v:2, n:nonce, c:ciphertext})</code>.
        V1 credentials (Laravel's standard encrypted format) are still readable — migration is transparent.
    </p>
    <x-docs.callout type="info">
        Even a database breach cannot expose raw credentials without the APP_KEY. The team DEK adds a second
        layer: compromising one team's key doesn't affect others.
    </x-docs.callout>

    {{-- 2. Tenant isolation --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">2. Tenant Isolation</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every Eloquent query against a team-scoped model automatically adds a
        <code class="rounded bg-gray-100 px-1 text-xs">WHERE team_id = ?</code> clause via a global scope (<code class="rounded bg-gray-100 px-1 text-xs">TeamScope</code>).
        There is no code path where one team can read another team's agents, experiments, credentials, or signals —
        even if an API token is shared or a URL is guessed.
    </p>

    {{-- 3. API token scoping --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">3. API Token Scoping</h2>
    <p class="mt-2 text-sm text-gray-600">
        Tokens are issued with <code class="rounded bg-gray-100 px-1 text-xs">['team:{team_id}']</code> abilities — never the wildcard
        <code class="rounded bg-gray-100 px-1 text-xs">['*']</code>. The <code class="rounded bg-gray-100 px-1 text-xs">ScopeTokenToTeam</code> middleware
        rejects requests where the token's team claim doesn't match the requested resource.
    </p>
    <p class="mt-2 text-sm text-gray-600">
        Changing your password immediately revokes all other active tokens (session tokens are also invalidated).
    </p>

    {{-- 4. Webhook replay protection --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">4. Webhook Replay Protection</h2>
    <p class="mt-2 text-sm text-gray-600">
        Inbound webhooks require an <code class="rounded bg-gray-100 px-1 text-xs">X-Webhook-Timestamp</code> header.
        Requests older than <strong>5 minutes</strong> are rejected with 401 — preventing replay attacks
        where an attacker re-sends a valid signed request at a later time.
    </p>

    {{-- 5. Outbound header allowlist --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">5. Outbound Header Allowlist</h2>
    <p class="mt-2 text-sm text-gray-600">
        Outbound webhook connectors only forward headers matching <code class="rounded bg-gray-100 px-1 text-xs">x-*</code>
        and <code class="rounded bg-gray-100 px-1 text-xs">content-type</code>.
        Headers like <code class="rounded bg-gray-100 px-1 text-xs">Authorization</code>,
        <code class="rounded bg-gray-100 px-1 text-xs">Host</code>, and <code class="rounded bg-gray-100 px-1 text-xs">Cookie</code>
        are stripped — preventing header injection attacks where a malicious actor configures an outbound
        connector to proxy authentication credentials.
    </p>

    {{-- 6. SSRF protection --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">6. SSRF Protection</h2>
    <p class="mt-2 text-sm text-gray-600">
        <code class="rounded bg-gray-100 px-1 text-xs">SsrfGuard</code> validates all outbound URLs (webhooks, RSS feeds, OAuth callbacks)
        against RFC 1918 private address ranges:
    </p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li><code class="rounded bg-gray-100 px-1 text-xs">10.0.0.0/8</code></li>
        <li><code class="rounded bg-gray-100 px-1 text-xs">172.16.0.0/12</code></li>
        <li><code class="rounded bg-gray-100 px-1 text-xs">192.168.0.0/16</code></li>
        <li><code class="rounded bg-gray-100 px-1 text-xs">127.0.0.0/8</code> (loopback)</li>
        <li><code class="rounded bg-gray-100 px-1 text-xs">169.254.0.0/16</code> (link-local / cloud metadata)</li>
    </ul>
    <p class="mt-2 text-sm text-gray-600">
        An attempt to configure a webhook pointing at an internal IP address returns a 422 validation error.
    </p>

    {{-- 7. Rate limiting --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">7. Rate Limiting</h2>
    <p class="mt-2 text-sm text-gray-600">Three independent rate-limiting layers:</p>
    <ul class="mt-2 list-disc pl-5 text-sm text-gray-600">
        <li><strong>HTTP login endpoint</strong>: 5 requests per minute (brute-force protection)</li>
        <li><strong>Token refresh endpoint</strong>: 10 requests per minute (credential stuffing protection)</li>
        <li><strong>AI gateway</strong>: per-tenant token-per-minute limits enforced before LLM calls</li>
        <li><strong>Outbound channels</strong>: per-channel and per-target rate limits (e.g. max 1 email/hour to the same address)</li>
    </ul>

    {{-- 8. Semantic cache --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">8. Semantic Cache Privacy</h2>
    <p class="mt-2 text-sm text-gray-600">
        LLM responses are cached using pgvector cosine similarity (threshold 0.92). Only the
        <strong>normalised prompt text</strong> is stored in the cache — never raw credentials, PII, or
        personally identifiable data. Cache lookups bypass team scoping by design (cross-team efficiency),
        but the stored text is sanitised before indexing.
    </p>

    {{-- 9. Audit log --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">9. Immutable Audit Log</h2>
    <p class="mt-2 text-sm text-gray-600">
        Every experiment transition, approval decision, budget event, agent event, and credential access is
        written to <code class="rounded bg-gray-100 px-1 text-xs">audit_entries</code> — an append-only table. Entries are never updated
        or deleted within the retention window. Retention is enforced by your plan (see
        <a href="{{ route('docs.show', 'audit-log') }}" class="text-primary-600 hover:underline">Audit Log</a>).
    </p>

    {{-- 10. Budget circuit breaker --}}
    <h2 class="mt-10 text-xl font-bold text-gray-900">10. Budget Circuit Breaker</h2>
    <p class="mt-2 text-sm text-gray-600">
        The <code class="rounded bg-gray-100 px-1 text-xs">PauseOnBudgetExceeded</code> event listener fires on every
        <code class="rounded bg-gray-100 px-1 text-xs">ExperimentTransitioned</code> event. If the team's or project's budget
        is exhausted, the experiment is automatically paused before the next queue job can start —
        preventing runaway spending even if multiple jobs are already queued.
        Usage counters are atomically incremented with Lua scripts (preventing TOCTOU races in Redis).
    </p>
</x-layouts.docs>
