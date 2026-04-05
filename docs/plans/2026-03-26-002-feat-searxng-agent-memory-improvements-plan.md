---
title: "feat: Searxng Installation, Agent Memory Improvements & Paid-User Gating"
type: feat
status: completed
date: 2026-03-26
---

# feat: Searxng Installation, Agent Memory Improvements & Paid-User Gating

## Overview

Three related improvements to the agent execution pipeline:

1. **Searxng on production** — Install Searxng as a Docker service, fix the `SsrfGuard` blocker that prevents internal Docker hostnames from being used as operator-configured URLs, and expose Searxng search to agents.
2. **Agent memory improvements** — Enable `use_memory: true` + `enable_scout_phase: true` on the PinPorn Reddit Publisher agent (and document how to enable for others).
3. **Paid-user gating** — Restrict Searxng search tool access to paid plan teams (`starter`, `pro`, `enterprise`) via `PlanEnforcer`.

## Problem Statement / Motivation

- **Searxng blocked by SsrfGuard**: `SearxngConnector::search()` and `poll()` both call `$this->ssrfGuard->assertPublicUrl($url)` (lines 63 and 124). In production, `services.ssrf.validate_host = true` is forced by `CloudServiceProvider`. Docker-internal hostname `searxng` resolves to `172.x.x.x` (RFC 1918) → throws `InvalidArgumentException`. This is a critical bug that must be fixed before any production deployment.
- **PreExecutionScout underutilized**: The system has a `PreExecutionScout` middleware that generates targeted memory retrieval queries before execution, but the PinPorn agent doesn't have `enable_scout_phase: true` in its config.
- **Memory tier `Successes` unused for PinPorn agent**: A `MemoryTier::Successes` tier was added (commit `8d9e49d`) with a +0.10 retrieval boost, but agents need `use_memory: true` to benefit from it.
- **Free-tier abuse**: Searxng is a compute-intensive service; exposing it to free-tier teams increases infrastructure costs with no revenue.

## Proposed Solution

### Phase 1: Fix SsrfGuard for operator-configured URLs

Add a bypass mechanism in `SsrfGuard` so that operator-configured URLs (set by admins, not end users) can point to internal Docker services. The cleanest approach: add an `allowInternal(bool $allow = true)` fluent method (or an `assertInternalUrl()` method) to `SsrfGuard` that skips RFC 1918 blocking when the URL comes from platform config rather than user input.

**Alternative**: Update `SearxngConnector` to skip the SSRF guard call entirely when using the operator-configured URL from `GlobalSetting`/`config('services.searxng.url')`. The SSRF guard exists to protect against *user-supplied* URLs — the operator URL is trusted.

**Chosen approach**: Add `skipSsrfCheck: bool` parameter to `SearxngConnector::search()` and `poll()`, defaulting to `false`. Call sites that use the operator URL (MCP tool, connector poll) pass `skipSsrfCheck: true`. This keeps the guard in place for any future user-supplied URL paths.

### Phase 2: Install Searxng on production

Add `searxng` service to `docker-compose.prod.yml` with a minimal settings file. Searxng should be internal-only (no host port exposure). Set `SEARXNG_URL=http://searxng:8888` in production `.env`.

### Phase 3: Gate behind paid plans

Add `searxng_access` feature flag to `cloud/config/plans.php` — enabled for `starter`, `pro`, `enterprise`, disabled for `free`. Gate `SearxngSearchTool::handle()` and `SearxngConnector` usage behind `PlanEnforcer::hasFeature($team, 'searxng_access')`.

### Phase 4: Enable memory + scout phase for PinPorn agent

Update the PinPorn Reddit Publisher agent's `config` JSONB column to include `use_memory: true` and `enable_scout_phase: true`. This is a one-time data migration / MCP tool call.

## Technical Considerations

### SsrfGuard fix — `SearxngConnector`

```php
// app/Domain/Signal/Connectors/SearxngConnector.php

public function search(string $query, array $options = [], bool $skipSsrfCheck = false): array
{
    $url = config('services.searxng.url') ?? GlobalSetting::get('searxng_url');
    if (! $url) {
        return [];
    }
    if (! $skipSsrfCheck) {
        $this->ssrfGuard->assertPublicUrl($url);
    }
    // ... existing HTTP call
}

public function poll(array $options = [], bool $skipSsrfCheck = false): array
{
    // Same pattern
}
```

Call sites passing `skipSsrfCheck: true`:
- `SearxngSearchTool::handle()` — operator URL, safe
- `connectors:poll` command — operator URL, safe

### `docker-compose.prod.yml` addition

```yaml
searxng:
  image: searxng/searxng:latest
  restart: unless-stopped
  volumes:
    - ./searxng:/etc/searxng
  environment:
    - SEARXNG_BASE_URL=http://searxng:8888/
  networks:
    - app-network
```

Add `searxng/settings.yml` to the repo with minimal config:
- `use_default_settings: true`
- JSON format for API responses
- Disable web UI (or keep minimal)
- Engines: Google, Bing, DuckDuckGo

### `cloud/config/plans.php` addition

```php
'features' => [
    // existing...
    'searxng_access' => false,   // free
],

// starter:
'searxng_access' => true,

// pro:
'searxng_access' => true,

// enterprise:
'searxng_access' => true,
```

### `SearxngSearchTool::handle()` gating

```php
// app/Mcp/Tools/Signal/SearxngSearchTool.php

public function handle(Request $request): Response
{
    $team = auth()->user()->currentTeam;
    if (! app(PlanEnforcer::class)->hasFeature($team, 'searxng_access')) {
        return $this->error('Searxng search requires a paid plan. Upgrade at /billing.');
    }
    // existing logic, pass skipSsrfCheck: true to connector
}
```

### Memory + Scout for PinPorn agent

Via MCP tool call or direct DB:
```sql
UPDATE agents
SET config = config ||
  '{"use_memory": true, "enable_scout_phase": true}'::jsonb
WHERE name = 'Reddit Publisher'
  AND team_id = '<pinporn-team-id>';
```

Or via `agent_update` MCP tool:
```json
{
  "agent_id": "<id>",
  "config": {
    "use_memory": true,
    "enable_scout_phase": true
  }
}
```

## System-Wide Impact

- **SsrfGuard bypass**: Scoped to `SearxngConnector` only — the guard remains enforced for all other connectors and user-supplied URLs. No security regression.
- **Docker networking**: `searxng` service joins `app-network` — accessible by `app`, `horizon`, `scheduler` containers. No external exposure.
- **Plan enforcement**: `SearxngSearchTool` is the only MCP tool to access Searxng; gating here is sufficient. Connector poll is scheduled by admins (already trusted).
- **Memory injection**: `PreExecutionScout` uses cheapest model (Haiku); enabling it adds ~$0.001/run. Negligible cost.

## Acceptance Criteria

- [ ] `SearxngConnector::search()` works with `http://searxng:8888` in production (no `InvalidArgumentException`)
- [ ] Searxng Docker service starts with `docker compose -f docker-compose.prod.yml up -d`
- [ ] `SEARXNG_URL=http://searxng:8888` set in production `.env`
- [ ] `SearxngSearchTool` returns error for free-tier teams; succeeds for paid teams
- [ ] `cloud/config/plans.php` has `searxng_access: true` on starter/pro/enterprise, `false` on free
- [ ] PinPorn Reddit Publisher agent has `use_memory: true` and `enable_scout_phase: true` in config
- [ ] `searxng/settings.yml` committed to repo (minimal, JSON output, safe engines)
- [ ] No SSRF regression — user-supplied URLs in other connectors still blocked as before

## Dependencies & Risks

| Risk | Mitigation |
|------|-----------|
| Searxng image size (~500MB) | Use `searxng/searxng:latest` — already lightweight Alpine-based |
| Searxng rate limiting by search engines | Set reasonable `request_timeout` and engine limits in `settings.yml` |
| SsrfGuard bypass creep | The bypass is a named parameter, not a global flag — cannot accidentally enable |
| Memory scout adds latency | `PreExecutionScout` is async and uses cheapest model; adds ~1s max |
| Docker volume for Searxng config | Mount `./searxng:/etc/searxng` — needs `mkdir searxng` + `settings.yml` on VPS |

## Files to Create / Modify

| File | Action | Notes |
|------|--------|-------|
| `base/app/Domain/Signal/Connectors/SearxngConnector.php` | Modify | Add `skipSsrfCheck` param to `search()` and `poll()` |
| `base/app/Mcp/Tools/Signal/SearxngSearchTool.php` | Modify | Add plan gate + pass `skipSsrfCheck: true` |
| `docker-compose.prod.yml` | Modify | Add `searxng` service |
| `searxng/settings.yml` | Create | Minimal Searxng config |
| `cloud/config/plans.php` | Modify | Add `searxng_access` feature flag |

## Sources & References

- `base/app/Domain/Signal/Connectors/SearxngConnector.php` — lines 63, 124 (SSRF guard calls)
- `base/app/Mcp/Tools/Signal/SearxngSearchTool.php` — operator URL, plan gate location
- `base/app/Domain/Shared/Services/SsrfGuard.php` — RFC 1918 blocking logic
- `base/app/Domain/Agent/Pipeline/Middleware/PreExecutionScout.php` — `isEnabled()` checks `agent.config['enable_scout_phase']`
- `cloud/config/plans.php` — feature flag definitions
- `cloud/Domain/Shared/Services/PlanEnforcer.php` — `hasFeature()` method
