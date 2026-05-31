# Plan — Make autonomous pipeline honour BYOK provider availability (no hard Anthropic dependency)

**Date:** 2026-05-31
**Status:** Draft — awaiting approval before implementation
**Repo:** base/ (community core); no cloud/ override expected
**Decision (founder):** Platform-run autonomous agents on a no-credit team must fall
back to a provider the team actually has a BYOK key for (Google/OpenAI), NOT a
hard-coded Anthropic model. claude-code-vps remains available but is NOT required
for these projects to run.

---

## Problem (verified on prod 2026-05-30/31, team PriceX 019cb001)

Continuous projects ("Medium Article Discovery", "Daily Price Watch") run but
every experiment dies. Root causes, all confirmed:

1. **Hard Anthropic dependency in pipeline/agent sub-calls.** ~30 call sites
   hard-code `provider: 'anthropic', model: 'claude-haiku-4-5'` (or
   `config('llm_pricing.default_provider','anthropic')`). The team has NO
   anthropic key (BYOK = google/openai/groq/openrouter/reddit/runpod). Result:
   `Anthropic Error [401]: x-api-key header is required` → stage throws →
   `scoring_failed`/`planning_failed` → project flips `failed`.
   - The 401 call observed had `purpose=null`, i.e. it is NOT the main
     scoring/planning call (those use `resolvePipelineLlm` → team default and are
     fixable by config). It is one of the hard-coded sub-calls below.

2. **claude-code-vps concurrency cap is fatal.** `LocalAgentGateway` caps 2
   concurrent calls/team and throws `VpsLocalAgentException`; `BaseStageJob`
   treats it as a hard failure on attempt 1 (observed in prod log).

3. **Executing stage routes to local agents that hang under Horizon.** Same class
   as the documented "claude-code-vps HANGS under Horizon" that forced Sentry
   triage onto Groq (mem features/sentry-watchdog-bugfix-2026-05-17).

This plan targets (1) primarily (the BYOK gate), plus a minimal, safe slice of
(2). (3) is called out but scoped OUT of the first PR (see "Out of scope").

---

## Goal / acceptance

A continuous project owned by a team with at least one cloud BYOK key (no
anthropic) completes a run end-to-end:
- scoring → planning → building → executing all use a provider the team has a key
  for (or claude-code-vps when that IS the resolved/available provider),
- no `x-api-key header is required` error,
- the Medium project produces an artifact and the email-delivery proposal is
  created.

Measured by: a feature test (provider matrix) + a real prod run of the Medium
project after deploy (schedule re-enabled only once green).

---

## Design — one resolver helper, applied at every hard-coded site

### Core change: `ProviderResolver::resolveInternal()` (new)

Add a single public method for "internal/utility" LLM calls that today hard-code
Anthropic (memory extraction, summarisation, scouts, judges, classifiers):

```php
/**
 * Resolve a provider/model for internal utility calls (memory, summarise,
 * scout, judge, classify) that previously hard-coded anthropic/claude-haiku.
 * Honours BYOK availability: prefers the team's configured cheap-tier provider,
 * falls back across providers the team/platform actually has keys for.
 *
 * @param 'cheap'|'standard'|'expensive' $tier
 * @return array{provider:string, model:string}
 */
public function resolveInternal(?Team $team, string $tier = 'cheap'): array
```

Behaviour:
1. If team default provider is a cloud provider the team has a key for → use the
   tier model for that provider (from `config('experiments.model_tiers.{tier}')`).
2. Else iterate `model_tiers.{tier}` provider→model and pick the first the team
   has via `teamHasProvider()` (existing private method) OR platform env key.
3. Else fall back to the existing platform default
   (`GlobalSetting::get('default_llm_provider')` → config). Only here may it be
   anthropic — and only if a key exists.
4. NEVER return a provider with no available key. If nothing is available, throw
   a typed `NoAvailableProviderException` (new) so callers fail loudly with a
   clear message instead of a downstream 401.

Reuse the existing tier tables (`config/experiments.php` `model_tiers`/
`stage_model_tiers`) — they already list anthropic/openai/google per tier. No new
config.

### Apply the helper at the hard-coded sites

Replace literal `provider:'anthropic', model:'claude-haiku-4-5'` with
`resolveInternal($team, 'cheap')` (or appropriate tier). Priority order — fix the
ones on the **autonomous project run path** first:

**Tier A (on the failing path — must fix):**
- `app/Domain/Agent/Pipeline/Middleware/PreExecutionScout.php` (SCOUT_MODELS) —
  already resolves provider via ProviderResolver, but the model map is keyed only
  by anthropic/openai/google; if resolved provider is `claude-code-vps` it bails.
  Make it use `resolveInternal` so a cloud key is used for the scout.
- `app/Domain/Agent/Pipeline/Middleware/SummarizeContext.php` (SUMMARIZE_MODEL).
- `app/Domain/Agent/Pipeline/Middleware/DetectClarificationNeeded.php` (DETECT_MODEL).
- `app/Domain/Experiment/Services/PipelineContextCompressor.php` (anthropic/haiku).
- `app/Domain/Experiment/Services/DoneConditionJudge.php` (override defaults).
- `app/Domain/Experiment/Actions/PlanWithKnowledgeAction.php` (haiku literal).
- `app/Infrastructure/AI/Middleware/ContextCompaction.php` (summarizer_model default).

**Tier B (memory/skill background jobs — fix for correctness, lower urgency):**
- `app/Domain/Memory/Actions/*` (Extract/Store/Classify/Consolidate/Distill —
  ~10 files, several already read `config('memory.*.model')` so just need the
  config default made provider-aware OR switch to resolveInternal).
- `app/Domain/Skill/Actions/*` (ExtractSkillFromTrajectory, ProposeNewSkill,
  AutoSkillCreationService).
- `app/Domain/Marketplace/Actions/ScanListingRiskAction.php`.
- `app/Domain/Website/Actions/GenerateWebsiteFromPromptAction.php`.

**Tier C (utility, leave or config-default only):** `GenerateAgentNameAction`,
Assistant tools, DryRunAgentAction — user-facing/manual, lower blast radius. Fix
opportunistically.

> Note: this is a large surface. The PR fixes **Tier A** (unblocks the autonomous
> path) + the shared `resolveInternal` helper + the memory/skill **config
> defaults** that are already config-driven (cheap, no risk). Tier B/C literal
> call sites that are NOT config-driven get a follow-up PR to keep the diff
> reviewable. Each tier is independently shippable.

### Minimal claude-code-vps safety (root cause #2) — include in this PR

In `BaseStageJob::handle()` catch block (or via `$this->release()`), treat
`VpsLocalAgentException` (cap reached) as **retryable**: re-release with backoff
instead of transitioning to `*_failed`. BaseStageJob already has `$tries=3,
$backoff=60`; the cap exception should bubble as a normal retry, not a terminal
fail. Small, isolated, well-tested.

---

## Out of scope (separate, tracked)

- **Executing-stage local-agent hang under Horizon (#3).** Real but needs its own
  reproduction + fix (subprocess/stream-read deadlock). Tracked in
  mem feedback_executing_stage_forces_local_agent_hangs. The Medium agent's
  EXECUTING stage will only fully work once either this is fixed OR the agent is
  pinned to a cloud provider for execution. After this PR, set the Medium agent's
  `agent.provider/model` to a cloud BYOK pair so its executing stage uses cloud
  tool-calling (config, not code) and re-enable its schedule.
- **signalio watchdog 0-ingest.** Separate: check `integrations:poll` for project 6.

---

## Files touched (Tier A PR)

- `app/Infrastructure/AI/Services/ProviderResolver.php` (+resolveInternal, reuse teamHasProvider)
- `app/Infrastructure/AI/Exceptions/NoAvailableProviderException.php` (new)
- 7 Tier-A call sites above
- `app/Domain/Experiment/Pipeline/BaseStageJob.php` (retry on VpsLocalAgentException)
- config defaults made provider-aware where already config-driven (memory.*)

## Tests

- Extend `tests/Feature/Infrastructure/AI/AiGatewayProviderMatrixTest.php`:
  team with ONLY google BYOK → every Tier-A sub-call resolves google, never
  anthropic; asserts no AiRequestDTO leaves with provider lacking a key.
- Unit: `ProviderResolverInternalTest` — google-only team → cheap tier returns
  google/gemini-2.5-flash; no-key team → throws NoAvailableProviderException.
- Feature: `BaseStageJob` releases (not fails) on VpsLocalAgentException; reaches
  terminal fail only after `$tries`.
- Feature: end-to-end project run on a google-only team reaches `building`
  without a 401 (fake gateway asserts provider==google on each call).

## Rollout

- base/ feature branch `fix/pipeline-byok-provider-availability` → PR into base
  develop. No deploy until approved.
- Activation: pure code; no env flag. Safe because behaviour only CHANGES for
  teams currently hitting 401 (they were 100% failing anyway). Teams with an
  anthropic key keep current behaviour (resolveInternal prefers their default).
- After deploy + green: pin Medium agent to a cloud provider, re-enable Medium +
  Price Watch schedules, run one manual Medium run, confirm artifact + email.

## Risks

- Large literal surface → mitigated by tiering (A first, B/C follow-up).
- `model_tiers.standard` is `null` (means "use default") → resolveInternal must
  treat standard tier as "team default or first available", not crash on null.
- Changing memory/skill background models from haiku→gemini slightly changes
  output style; acceptable (these are utility extractions), covered by existing
  JSON-parse-tolerant code.
- Prompt-caching headers are Anthropic-specific; cloud providers ignore them
  (already handled — `enablePromptCaching` is a no-op for non-anthropic).
