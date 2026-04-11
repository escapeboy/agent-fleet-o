# Design — Claude Code on VPS (super-admin gated)

**Date:** 2026-04-11
**Feature branch:** `feat/claude-code-vps`
**Status:** approved to build
**Owner:** katsarov (super admin)

---

## The 6 forcing questions

### 1. Who needs this? What are they doing today?

**Who:** super-admin (katsarov) + members of teams he explicitly whitelists in the super-admin dashboard.

**Today:**
- Cloud API path (`PrismAiGateway`) works but costs money — katsarov is paying per-token on top of an already-owned Claude Max subscription.
- `fleetq-bridge` relay path (which would let him use Max OAuth from his home server) **is not usable** because his home network is unstable — bridge connections drop mid-execution, stranding jobs.
- Result: he's paying twice. Claude Max is sitting idle while FleetQ burns cloud-API credits.

### 2. What's the narrowest MVP someone would pay for?

A single new provider `claude_code_vps` that:
1. Spawns `claude -p "..."` on the VPS using a pre-provisioned `CLAUDE_CODE_OAUTH_TOKEN` (Max subscription token).
2. Only shows up in the provider picker when the current user is `is_super_admin = true` OR the current team has `claude_code_vps_allowed = true`.
3. Has an admin-dashboard toggle per team.
4. Runs each invocation in an ephemeral tmpdir with a 300s timeout and a concurrency cap.
5. Logs every call to the audit trail.

No fancy streaming UI, no model picker, no multi-token rotation, no fallback chain in v1.

### 3. What would make someone say "whoa"?

Effectively **zero marginal cost** for heavy agent workflows. katsarov can run a 30-step workflow with tool use + file edits and pay nothing extra above his already-owned Max plan. This changes the math on what kinds of features are worth building (any agent loop becomes free once gated in).

### 4. How does this compound?

- Every new team katsarov owns → one toggle → free inference.
- Better prompts = more tokens used = more hits against Max quota = eventually throttled. So this feature forces token discipline. Token-optimization best practices (from `github.com/escapeboy/ai-prompts`) become **operationally necessary**, not optional.
- Audit log lets us reconstruct usage patterns per team, which informs the next round of prompt optimization.

### 5. Constraints (business + legal)

- **ToS:** routing Max-plan credentials on behalf of users is literally the clause Anthropic prohibits. Katsarov has explicitly acknowledged the risk. Scope is tight (super-admin + his own teams, no external customers, no reselling). Risk profile: account suspension, not legal/criminal. Acceptable to him.
- **Max rate limits:** rolling 5-hour windows. With concurrency cap = 2 and explicit whitelist, realistic burn is manageable. No fallback to paid API in v1 — if Max throttles, users see a clear error.
- **Network:** bridge relay is not an option (unstable home network). VPS-local is the only path that works.
- **OAuth token lifecycle:** 1-year token, manual rotation via `claude setup-token` on katsarov's laptop + `.env` update + container restart. Good enough for v1.

### 6. Non-goals (explicitly out of scope)

- Multi-token rotation / load balancing across multiple Max accounts.
- Per-user quota enforcement (Max is shared — acceptable for the scope).
- Streaming tool-use events to the assistant UI (existing `LocalAgentGateway` streaming path is reused for the final text stream, but we don't add new events).
- Cloud API fallback when rate-limited (user explicitly said NO — the whole point is "not paying for API").
- Exposing the feature to non-whitelisted teams even read-only.
- Replacing the existing fleetq-bridge relay path — both continue to coexist.
- Dockerfile changes to production image (will be applied in separate ops PR; for now, `claude` is installed manually on the VPS outside the container via bind-mount, OR in a follow-up infra PR).

---

## Success criteria

Shippable when:
1. Super-admin can toggle a team's `claude_code_vps_allowed` flag from the admin dashboard and see the change reflected in the provider picker for a member of that team within one page refresh.
2. A non-whitelisted team member cannot see or select the provider by any route (Livewire, API v1, MCP tool).
3. Running an LLM call through the new provider spawns `claude -p` with `CLAUDE_CODE_OAUTH_TOKEN` set, in an ephemeral tmpdir, and returns the stdout as the response.
4. Each call produces an `AuditEntry` row with `action=claude_code_vps.invoke`, team_id, user_id, prompt length, duration, exit code.
5. Concurrency cap is enforced: a 3rd concurrent call while 2 are running gets rejected with a clear error.
6. All tests pass (gating tests + execution smoke test + cap test).

## Applied token-optimization practices (from ai-prompts)

From `github.com/escapeboy/ai-prompts/05-token-optimization/guide.md`:

1. **Cache-friendly prompt ordering** — the `LocalAgentPromptBuilder` stable sections (system identity, rules) go first; volatile context (team id, timestamps) goes last. Already mostly the case for existing `claude-code` path; will verify and not regress.
2. **Short, stable system prompts** — reuse existing compact system prompt builder; do not add VPS-specific bloat.
3. **`--output-format stream-json`** — already used; we keep it (efficient parsing).
4. **No per-call context re-injection of MEMORY.md / CLAUDE.md** — `claude -p` already auto-loads them. Don't duplicate in the user prompt.

The only VPS-specific concern is the ephemeral tmpdir: it won't have a `CLAUDE.md` or `MEMORY.md`, so the token cost per call is actually *lower* than a normal interactive session — we benefit from having no "session state" to re-send.
