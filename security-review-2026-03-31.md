# Security Review: Per-Team AI Feature Toggles + Agent Fields (2026-03-31)

Commits reviewed: `a5bb80d`, `08c8ac6` on `develop` branch.

## Risk Matrix

| # | Severity | Finding | File(s) |
|---|----------|---------|---------|
| 1 | **HIGH** | IDOR on `knowledge_base_id` — MCP AgentCreateTool and AgentUpdateTool | `app/Mcp/Tools/Agent/AgentCreateTool.php:69`, `AgentUpdateTool.php:76,137-139` |
| 2 | **HIGH** | Mass assignment — `budget_spent_credits`, `risk_score` in `$fillable` | `app/Domain/Agent/Models/Agent.php:71,80-82` |
| 3 | **MEDIUM** | No authorization gate on `saveAiFeatures()` — viewers can modify | `app/Livewire/Teams/TeamSettingsPage.php:238-264` |
| 4 | **MEDIUM** | MCP `team_ai_features_update` — no role check | `app/Mcp/Tools/Shared/TeamAiFeaturesUpdateTool.php:39` |
| 5 | **MEDIUM** | Missing validation on `stage_model_tiers` values in MCP tool | `app/Mcp/Tools/Shared/TeamAiFeaturesUpdateTool.php:69` |
| 6 | **LOW** | `heartbeat_definition` accepts arbitrary JSON array — no schema validation | `StoreAgentRequest.php`, `AgentCreateTool.php`, `AgentUpdateTool.php` |
| 7 | **LOW** | Livewire `save()` missing validation on `editEvaluationSampleRate` bounds | `app/Livewire/Agents/AgentDetailPage.php:236-242` |

---

## Detailed Findings

### 1. IDOR on `knowledge_base_id` (HIGH)

**MCP tools** validate `knowledge_base_id` as `'nullable|uuid'` but do NOT scope to the current team. An attacker can link an agent to ANY team's knowledge base by providing a valid UUID.

**API layer** (`StoreAgentRequest`, `UpdateAgentRequest`) correctly uses `Rule::exists('knowledge_bases', 'id')->where('team_id', $teamId)` — this is SAFE.

**Livewire** (`AgentDetailPage.php:240`, `CreateAgentForm.php:419`) writes `knowledge_base_id` directly from user input without team validation in `save()`. However, the dropdown only renders same-team KBs (`KnowledgeBase::where('team_id', $teamId)`), so exploitation requires crafting raw Livewire requests.

**Remediation:**
- `AgentCreateTool.php:69`: Change to `'nullable|uuid|exists:knowledge_bases,id'` AND add a post-validation team_id check (or use `Rule::exists` with team_id constraint).
- `AgentUpdateTool.php:76`: Same fix.
- Livewire: Add `$this->validate(['editKnowledgeBaseId' => ['nullable', 'uuid', Rule::exists('knowledge_bases', 'id')->where('team_id', $teamId)]])` in `save()`.

### 2. Mass Assignment — `budget_spent_credits`, `risk_score` in `$fillable` (HIGH)

`Agent::$fillable` includes `budget_spent_credits`, `risk_score`, `risk_profile`, `risk_profile_updated_at`. These are system-computed fields that should never be user-writable.

While the API `StoreAgentRequest`/`UpdateAgentRequest` do NOT list these fields in validation rules, the **MCP tools** and **Livewire** use `$agent->update($data)` with arrays built from validated input — but `$fillable` is the last line of defense. If any code path passes unfiltered data to `update()`, these fields become writable.

The **AgentController** uses `$agent->update($extraFields)` where `$extraFields` is built from `$request->input(...)` calls for specific keys — safe. But defense-in-depth says: remove system fields from `$fillable`.

**Remediation:** Remove `budget_spent_credits`, `risk_score`, `risk_profile`, `risk_profile_updated_at`, `cost_per_1k_input`, `cost_per_1k_output`, `last_health_check` from `$fillable`. Use `$agent->forceFill()` in system code that needs to write these.

### 3. No Authorization on `saveAiFeatures()` (MEDIUM)

`TeamSettingsPage::saveAiFeatures()` has no `Gate::authorize()` check. The `TeamSettingsPage` route has no role-based middleware. Any authenticated team member (including `viewer` role) can modify AI feature settings. Other save methods in the same file (`saveBridgeRouting`, `saveChatbotSettings`, etc.) also lack authorization — this is a pre-existing pattern, not unique to this change.

**Remediation:** Add `Gate::authorize('manage-team')` at the top of `saveAiFeatures()` (and all other save methods). Alternatively, add middleware to the route.

### 4. MCP `team_ai_features_update` — No Role Check (MEDIUM)

The tool uses `Team::first()` (with TeamScope) to get the current team, then directly updates settings. No check that the MCP user has admin/owner role. Any team member with an API token can modify team AI features.

**Remediation:** Add role check: `$user = auth()->user(); if (!$user || !in_array($user->teamRole()?->value, ['owner', 'admin'])) return Response::error('Forbidden');`

### 5. Missing Validation on `stage_model_tiers` (MEDIUM)

`TeamAiFeaturesUpdateTool` accepts `stage_model_tiers` as an opaque object and writes it directly to `team.settings`. An attacker could inject arbitrary keys or non-string values. The consumers (`ProviderResolver`, `BaseStageJob`) do `$teamTiers[$stageKey] ?? null` — arbitrary keys won't cause immediate harm, but polluted settings could be confusing.

**Remediation:** Validate allowed keys (`scoring`, `planning`, `building`, `executing`, `collecting_metrics`, `evaluating`) and values (`cheap`, `standard`, `expensive`, or `null`).

### 6. `heartbeat_definition` — No Schema Validation (LOW)

All three entry points (`StoreAgentRequest`, `AgentCreateTool`, `AgentUpdateTool`) validate as `'nullable|array'` but don't validate the internal structure. The field is stored as JSONB. While not directly exploitable, it could lead to unexpected behavior if malformed data is stored.

**Remediation:** Add nested validation: `'heartbeat_definition.enabled' => 'boolean'`, `'heartbeat_definition.cron' => 'string|max:100'`, `'heartbeat_definition.prompt' => 'string|max:2000'`.

### 7. Livewire Missing Bounds on `editEvaluationSampleRate` (LOW)

`AgentDetailPage::save()` writes `editEvaluationSampleRate` without validating min:0/max:1. The API layer validates correctly. Since Livewire properties are typed as `?float`, a value like -5.0 or 100.0 could be stored.

**Remediation:** Add `$this->validate(['editEvaluationSampleRate' => 'nullable|numeric|min:0|max:1'])` in `save()`.

---

## What's Done Well

- **Tenant isolation on settings reads**: All `Team::withoutGlobalScopes()->find($entity->team_id)` calls correctly use the entity's own `team_id` — no cross-team leakage possible. The team is looked up by the entity's FK, not by user input.
- **API validation**: `StoreAgentRequest` and `UpdateAgentRequest` properly bound `evaluation_sample_rate` (min:0, max:1), scope `knowledge_base_id` and `skill_ids` to the team.
- **MCP allowlist pattern**: `TeamAiFeaturesUpdateTool` uses an explicit `$allowedKeys` array — arbitrary JSONB keys cannot be injected beyond the 11 allowed keys.
- **GlobalSettingsPage**: Protected by `SuperAdmin` middleware (route-level). Validation bounds are correct (TTL 5-1440, thresholds 0-1, sample size 1-1000).
- **Config fallback chain**: Team settings gracefully fall back to `config()` defaults — no null dereference risk.
