# Test plan — Claude Code on VPS

## Unit tests

### `ClaudeCodeVpsGateTest`

1. `isConfigured` returns false when oauth_token is empty
2. `isConfigured` returns true when oauth_token is set
3. Super-admin user is always allowed (regardless of team flag)
4. Non-super-admin with team where `claude_code_vps_allowed = false` → not allowed
5. Non-super-admin with team where `claude_code_vps_allowed = true` → allowed
6. Non-super-admin with no current team → not allowed
7. `assertAllowed()` throws `VpsLocalAgentException` when not allowed
8. Disabled at feature-flag level (`local_agents.enabled = false`) → not allowed for anyone, even super admin

### `ClaudeCodeVpsConcurrencyCapTest`

1. First acquire returns a token string
2. Second acquire under the cap returns a token string
3. Acquire when cap is reached returns `false`
4. Release frees a slot — next acquire succeeds
5. Release with wrong token is a no-op (does not free other slots)
6. Cap is per-team (team A acquires 2, team B can still acquire)
7. TTL safety net cleans up stale locks (integration-style: set a lock with TTL=1, wait, acquire succeeds)

## Feature tests

### `LocalAgentGatewayVpsTest`

Using a **fake claude binary** shim (shell script in the test `fixtures/` dir that echoes stream-json and exits 0):

1. Happy path: super-admin triggers `complete()` with `provider=claude_code_vps` → subprocess runs, stdout parsed, `AiResponseDTO` returned, `AuditEntry` row exists with action `claude_code_vps.invoke`.
2. Working dir is ephemeral — tmpdir created, cleaned up on success.
3. Working dir is cleaned up on failure (exception in parsing).
4. `CLAUDE_CODE_OAUTH_TOKEN` env var is set on the child process (assert by having the shim echo its env and capturing).
5. Gate denial: non-whitelisted team user triggers complete() → throws `VpsLocalAgentException` before process starts.
6. Cap hit: seed 2 active slots for a team, trigger 3rd call → throws `VpsLocalAgentException('concurrency cap reached')`.
7. Binary missing (shim removed) → throws `VpsLocalAgentException('binary not available')`.
8. OAuth token missing → provider is not available (upstream filter); direct call throws `VpsLocalAgentException('not configured')`.

### `SuperAdminClaudeCodeVpsToggleTest` (cloud)

1. Super-admin can toggle `claude_code_vps_allowed` on a team via Livewire action.
2. Non-super-admin hitting the Livewire action → 403/unauthorized.
3. After toggle, the team's `claude_code_vps_allowed` reflects in DB and in the UI state.
4. Toggle is idempotent across multiple clicks.
5. Audit log entry is created for the toggle action.

## Integration smoke tests (manual, post-deploy)

Tested on fleetq.net after the OAuth token is provisioned and `claude` binary is installed:

1. Katsarov logs in → provider picker shows "Claude Code (VPS — super-admin)".
2. A team member of a non-whitelisted team logs in → provider picker does NOT show it.
3. Katsarov toggles access ON for team X → team X member refreshes → provider appears.
4. Katsarov toggles OFF → team X member refreshes → provider disappears.
5. Katsarov invokes one LLM call through the new provider → returns a real response within 30s, audit log shows the invocation, no cost entry in `credit_ledger`.
6. Three simultaneous calls from katsarov → 2 succeed, 3rd gets a "concurrency cap reached" error.

## Out-of-scope tests (deferred)

- Load testing (hit Max rate limit intentionally to verify error messaging). Defer to post-deploy hand test.
- Prompt-caching hit rate measurement. Defer to retro phase.
- OAuth token expiry behavior — not testable without actually waiting 1 year; document the symptom (all calls start returning auth errors) + the rotation runbook.
