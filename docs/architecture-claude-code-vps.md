# Architecture — Claude Code on VPS (super-admin gated)

**Reads:** `docs/design-claude-code-vps.md`
**Feature branch:** `feat/claude-code-vps`

---

## Data model changes

### Migration — `add_claude_code_vps_allowed_to_teams`

```sql
ALTER TABLE teams
  ADD COLUMN claude_code_vps_allowed boolean NOT NULL DEFAULT false;
```

No index needed — super-admin toggles are not on a hot path. The column is checked once per provider resolution, already inside a team-loaded Eloquent context.

### `Team` model

- Add `claude_code_vps_allowed` to `$fillable` and `$casts['claude_code_vps_allowed' => 'boolean']`.

### No new tables

- **Audit log**: reuse existing `AuditEntry` with `action = 'claude_code_vps.invoke'`, `metadata = { prompt_length, duration_ms, exit_code, model }`.
- **Concurrency tracking**: Redis counter `claude-vps:active-count:{team_id}` via INCR/DECR with a TTL safety net.

---

## Component map

```
┌──────────────────────────────────────────────────────────────────┐
│ Laravel (FleetQ base)                                            │
│                                                                  │
│  ProviderResolver::availableProviders()                          │
│   └─ for 'claude_code_vps': check super_admin OR team flag       │
│                                                                  │
│  LocalAgentDiscovery::detectVps()  ◄── NEW                        │
│   └─ direct probe /usr/local/bin/claude, bypasses relay/bridge   │
│                                                                  │
│  LocalAgentGateway::complete()                                   │
│   └─ new branch when $request->provider === 'claude_code_vps'    │
│       │                                                          │
│       ├─ ClaudeCodeVpsGuard::assertAllowed($user, $team)         │
│       ├─ ConcurrencyCap::acquire($teamId, max: 2)                │
│       ├─ mktemp -d → $workdir                                    │
│       ├─ spawn Process(['claude', '-p', ...], $workdir, env)     │
│       │    env: CLAUDE_CODE_OAUTH_TOKEN from config              │
│       ├─ parse stream-json, return AiResponseDTO                 │
│       ├─ write AuditEntry(claude_code_vps.invoke, ...)           │
│       └─ finally: rm -rf $workdir, ConcurrencyCap::release()     │
│                                                                  │
│  Cloud\Livewire\Admin\SuperAdminDashboard                        │
│   └─ toggleClaudeCodeVps(teamId) action                          │
│                                                                  │
│  Mcp\Tools\Shared\TeamVpsAccessManageTool  ◄── NEW                │
│   └─ super-admin-only MCP tool for toggling                      │
└──────────────────────────────────────────────────────────────────┘

VPS host (fleetq.net):
  /usr/local/bin/claude  ← installed once, outside container OR via follow-up Dockerfile PR
  .env CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat...
```

## Key design decisions

### 1. New provider `claude_code_vps`, NOT a flag on existing `claude_code`

**Why:** the existing `claude_code` provider is wired to bridge/relay discovery for end-user laptops. Mixing the VPS-local path into the same key would require gating logic in three different discovery branches. A dedicated provider is a clean seam.

### 2. Dedicated `detectVps()` discovery path

**Why:** the existing `LocalAgentDiscovery::detect()` short-circuits to `relayDiscover()` when relay mode is enabled (which it is on fleetq.net). VPS claude would never be found. A new method that always does direct `which claude` probe is clearer than a `$forceDirect` flag.

### 3. Gate BOTH on `ProviderResolver::availableProviders()` AND `LocalAgentGateway::complete()`

**Why:** defense in depth. `availableProviders()` is a filter for UI/API lists — a malicious user could still craft a direct API call specifying `provider: 'claude_code_vps'` and bypass it. The gateway-level guard is the real enforcement.

### 4. `ConcurrencyCap` via Redis `locks` connection

**Why:** we already have that Redis DB (DB 2) and the `SET NX EX` pattern (reused from today's `PerAgentSerialExecution` fix). 2 concurrent calls max per team is a sensible default; configurable via env.

### 5. Reuse `AuditEntry` for logging

**Why:** we already log `agent.execute`, `approval.approved`, etc. there. No new table, no new UI — the existing audit log viewer surfaces it for free.

### 6. Token from `config('local_agents.vps.oauth_token')` → `.env`

**Why:** matches project convention (no `env()` outside config). The token is loaded at request time via `config()`, not cached in a singleton — that way `config:cache` rebuild picks up rotations.

### 7. NO Dockerfile changes in this PR

**Why:** keeps the PR focused on logic + tests. The `claude` binary will be installed on the VPS host and bind-mounted into the `app` container via a small `docker-compose.override` or a follow-up infra PR. The code tolerates absent binary gracefully — the provider just doesn't appear.

---

## Config shape

### `config/local_agents.php` — add under `'agents'`

```php
'claude-code-vps' => [
    'name' => 'Claude Code (VPS)',
    'binary' => 'claude',
    'description' => 'Claude Code running on the VPS with pre-provisioned Max OAuth token (super-admin gated)',
    'detect_command' => 'claude --version',
    'requires_env' => null, // OAuth token, not API key
    'capabilities' => ['code_generation', 'file_editing', 'shell_execution', 'git', 'mcp'],
    'supported_modes' => ['sync'],
    'execute_flags' => ['-p', '--output-format', 'stream-json', '--dangerously-skip-permissions'],
    'stream_flags' => ['-p', '--output-format', 'stream-json', '--dangerously-skip-permissions'],
    'output_format' => 'stream-json',
    'requires_pty' => false,
    'vps_only' => true,
],

// new 'vps' sub-config
'vps' => [
    'oauth_token' => env('CLAUDE_CODE_OAUTH_TOKEN'),
    'binary_path' => env('CLAUDE_CODE_VPS_BINARY', '/usr/local/bin/claude'),
    'max_concurrency_per_team' => (int) env('CLAUDE_CODE_VPS_MAX_CONCURRENCY', 2),
    'timeout_seconds' => (int) env('CLAUDE_CODE_VPS_TIMEOUT', 300),
],
```

### `config/llm_providers.php` — add top-level

```php
'claude_code_vps' => [
    'label' => 'Claude Code (VPS — super-admin)',
    'local' => true,
    'vps' => true,
    'agent_key' => 'claude-code-vps',
    'models' => [
        'claude-sonnet-4-5' => ['label' => 'Claude Sonnet 4.5'],
        'claude-opus-4-6' => ['label' => 'Claude Opus 4.6'],
        'claude-haiku-4-5' => ['label' => 'Claude Haiku 4.5'],
    ],
    'supports_tools' => true,
    'zero_cost' => true, // billed via Max subscription, not per-token
],
```

---

## Gating rules (exact)

A user can see and invoke `claude_code_vps` IFF all of:

1. `config('local_agents.enabled') === true`
2. `config('local_agents.vps.oauth_token')` is non-empty
3. `LocalAgentDiscovery::detectVps()` finds the binary
4. At least one of:
   - `$user->is_super_admin === true`, OR
   - `$currentTeam->claude_code_vps_allowed === true`

Fail any of these → provider is unset from `availableProviders()` and direct calls to `LocalAgentGateway::complete()` with this provider throw `UnauthorizedLocalAgentException`.

---

## Files to create / modify

### Create (9)

1. `base/database/migrations/2026_04_11_000001_add_claude_code_vps_allowed_to_teams.php`
2. `base/app/Infrastructure/AI/Services/ClaudeCodeVpsGate.php` — small service: `isAllowedForUser(User, ?Team): bool`, `assertAllowed(...)` (throws), `isConfigured(): bool`
3. `base/app/Infrastructure/AI/Services/ClaudeCodeVpsConcurrencyCap.php` — `acquire(string $teamId): string|false` (returns token) / `release(string $teamId, string $token): void`
4. `base/app/Infrastructure/AI/Exceptions/VpsLocalAgentException.php` — thrown on gate fail / cap fail / binary missing
5. `base/app/Mcp/Tools/Shared/TeamVpsAccessManageTool.php` — super-admin MCP tool
6. `base/tests/Unit/Infrastructure/AI/ClaudeCodeVpsGateTest.php`
7. `base/tests/Unit/Infrastructure/AI/ClaudeCodeVpsConcurrencyCapTest.php`
8. `base/tests/Feature/Infrastructure/AI/LocalAgentGatewayVpsTest.php`
9. `cloud/tests/Feature/SuperAdminClaudeCodeVpsToggleTest.php`

### Modify (6)

1. `base/database/migrations` — ensure Team fillable/casts (not a new file, edits model)
2. `base/app/Domain/Shared/Models/Team.php` — fillable + cast
3. `base/config/local_agents.php` — new agent + `vps` sub-config
4. `base/config/llm_providers.php` — new provider entry
5. `base/app/Infrastructure/AI/Services/LocalAgentDiscovery.php` — `detectVps()`, `probeVps()`, `vpsBinaryPath()`
6. `base/app/Infrastructure/AI/Services/ProviderResolver.php` — gating branch in `availableProviders()` + `resolveLocalProvider()`
7. `base/app/Infrastructure/AI/Gateways/LocalAgentGateway.php` — vps branch in `complete()` (gate, acquire cap, spawn with token env, ephemeral tmpdir, audit)
8. `base/app/Mcp/Servers/AgentFleetServer.php` — register new tool
9. `cloud/Livewire/Admin/SuperAdminDashboard.php` — `toggleClaudeCodeVps($teamId)` action + property
10. `cloud/resources/views/livewire/admin/super-admin-dashboard.blade.php` — toggle column in the teams table

### Env additions

```
CLAUDE_CODE_OAUTH_TOKEN=sk-ant-oat...
CLAUDE_CODE_VPS_BINARY=/usr/local/bin/claude
CLAUDE_CODE_VPS_MAX_CONCURRENCY=2
CLAUDE_CODE_VPS_TIMEOUT=300
```

---

## Build order (to minimize merge conflicts + enable incremental tests)

1. Migration + Team model changes
2. Configs (local_agents.php + llm_providers.php)
3. `ClaudeCodeVpsGate` + test
4. `ClaudeCodeVpsConcurrencyCap` + test
5. `LocalAgentDiscovery::detectVps()` + existing test coverage sanity
6. `ProviderResolver` gating branch + test
7. `LocalAgentGateway` vps branch + test (with fake binary)
8. MCP tool + registration
9. Cloud: SuperAdminDashboard toggle + view + test
10. Pint + full test suite
