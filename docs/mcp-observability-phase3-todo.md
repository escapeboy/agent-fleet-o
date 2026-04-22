# MCP Observability — Phase 3 Deferred Work

> **Purpose:** Preserve actionable scope for the Phase 3 items that were deferred
> during the Phase 1→2c observability sprint (2026-04-22). Written explicitly so
> this work does not get lost between sessions.
>
> **Status as of 2026-04-22:** Phase 1 (infra) + Phase 2/2b/2c (321-file retrofit)
> complete. Phase 3 items below are **not blockers** — they are expansions of the
> observability surface area.

---

## Why this exists

The research report in `claudedocs/research_grpc_mcp_transport_2026-04-22.md`
enumerated 4 ideas. All four have landed in production-ready form. But during
the Phase 1 design gate, the user said "правим всичко" (do everything) rather
than MVP. The design doc at `docs/design-mcp-observability-sprint.md` identified
several items as "out of scope" for the MVP but never explicitly as "deferred."
This document **promotes those items from implicit-forgotten to explicit-planned**.

---

## Phase 3 scope (3 features, ~5-8h total)

### 3.1 Queue-job deadline propagation

**Problem.** `App\Mcp\DeadlineContext` is a request-scoped singleton. When an MCP
tool dispatches a queue job (e.g., `experiment_start` → `RunScoringStage` via
`BaseStageJob`), the deadline does not propagate. The job runs under the
Horizon worker's static timeout (30s–300s depending on supervisor), ignoring
the client's `deadline_ms`.

**Acceptance criteria.**
- Every `BaseStageJob` subclass accepts an optional `?int $deadlineMsRemaining`
  constructor arg and, on `handle()`, calls `app(DeadlineContext::class)->set($deadlineMsRemaining)`.
- `ExecuteCrewTaskJob`, `ExecuteAgentJob`, `ExecuteSkillJob`, `DispatchScheduledProjectsJob`
  get the same treatment.
- `CompactTool::handle()` when dispatching a job, captures
  `app(DeadlineContext::class)->remaining()` and passes it into the job constructor.
- Workflow DAG nodes inherit remaining deadline from parent when scheduling
  child stages (in `DispatchNextStageJob` listener).

**Files to touch (~6):**
- `base/app/Domain/Experiment/Pipeline/BaseStageJob.php` (constructor + handle)
- `base/app/Domain/Crew/Jobs/ExecuteCrewTaskJob.php`
- `base/app/Domain/Agent/Jobs/ExecuteAgentJob.php` (if exists — verify)
- `base/app/Domain/Experiment/Listeners/DispatchNextStageJob.php`
- `base/app/Mcp/Tools/Compact/CompactTool.php` (pass deadline when dispatching)
- `base/tests/Feature/Mcp/CompactToolDeadlineTest.php` (add queue-job test case)

**Risks.**
- Breaking existing job retries — deadline must be re-computed per attempt,
  not frozen at dispatch time.
- Clock skew between web process and queue worker — use monotonic clocks if possible.

**Estimate: ~2h**

---

### 3.2 Expanded OpenTelemetry spans

**Problem.** Current spans cover 3 layers: `mcp.tool.*` → `ai.gateway.*` → `llm.provider.*`.
Missing observability into:
- Database queries (N+1 detection, slow queries)
- Redis cache hits/misses
- SemanticCache hit ratio
- Outbound sends (Email/Telegram/Slack/Webhook)
- Queue job execution (including the parent→child trace link)

**Acceptance criteria.**
- DB spans via `DB::listen()` in `AppServiceProvider::boot()` — emit `db.query`
  spans with SQL (truncated) + duration attributes when telemetry is enabled.
- Cache spans around `Cache::remember`, `Cache::get`, `Cache::set` hot paths.
- SemanticCache spans already exist indirectly via `ai.gateway.*`; add explicit
  `cache.semantic.lookup` span with `hit`, `similarity_score`, `candidates_scanned` attrs.
- Outbound spans in each `OutboundConnectorInterface::send()` implementation.
- Queue job spans with W3C traceparent header propagation via Redis job payload.

**Files to touch (~8):**
- `base/app/Providers/AppServiceProvider.php` (DB::listen + terminating)
- `base/app/Infrastructure/AI/Middleware/SemanticCache.php`
- `base/app/Domain/Outbound/Connectors/*.php` (6 connectors)
- `base/app/Infrastructure/Telemetry/TracerProvider.php` (helper for job context serialization)
- `base/app/Jobs/Middleware/` (new `PropagateTraceContext` job middleware)

**Risks.**
- DB::listen overhead — must respect `telemetry.sample_rate` and ideally only
  emit when there's an active span parent.
- Trace context propagation across Redis is non-trivial — use W3C
  traceparent spec (`00-<trace_id>-<span_id>-<flags>`).

**Estimate: ~3h**

---

### 3.3 SSE explicit buffer caps

**Problem.** `connection_aborted()` is reactive — reacts when client closes
socket. But if client is slow (not closed), we can buffer unbounded LLM output
in PHP memory.

**Acceptance criteria.**
- Per-stream byte counter; abort stream if output exceeds 256KB without
  client `ob_flush()` progress.
- Per-stream time counter; abort if stream runs > 60s without client ack.
- New config keys in `config/chatbot.php` or a new `config/streaming.php`:
  `max_stream_bytes` (default 256KB), `max_stream_seconds` (default 60).
- Raises new `App\Exceptions\StreamBufferExceededException` (ErrorCode::ResourceExhausted).

**Files to touch (~3):**
- `base/app/Http/Controllers/Api/Chatbot/ChatController.php`
- `base/app/Http/Controllers/Api/V1/OpenAiCompatibleController.php`
- `base/app/Exceptions/StreamBufferExceededException.php` (new)
- `base/config/streaming.php` (new)
- `base/app/Mcp/ErrorClassifier.php` (add new exception → ResourceExhausted)

**Risks.**
- False positives — slow but valid clients (mobile networks). Config defaults
  must be generous. Possibly make it opt-in per endpoint.

**Estimate: ~1-2h**

---

## Bonus hardening (non-Phase-3, nice-to-have)

### 3.4 `#[AssistantTool]` annotation parity audit

Per memory `feedback_assistant_mcp_tool_bridging.md`, cloud assistant MCP bridging
requires `CloudAssistantToolRegistry::getTools()` to call
`$this->bridgedMcpTools('read/write/destructive')` in all three tier blocks.
After retrofitting 321 MCP tools, some may be missing the `#[AssistantTool]`
annotation entirely, breaking assistant visibility.

**Action:** grep for retrofitted tools that have `use HasStructuredErrors;`
but not `#[AssistantTool(`. Each match is either intentionally hidden from
assistant or a parity bug.

```bash
cd base
for f in $(grep -rl "use HasStructuredErrors;" app/Mcp/Tools/); do
  if ! grep -q "#\[AssistantTool(" "$f"; then
    echo "NO AssistantTool: $f"
  fi
done
```

**Estimate: ~1h audit + fixes**

---

### 3.5 README for MCP structured errors contract

Agent-facing docs explaining the `{error:{code, message, retryable, retry_after_ms?, details?}}`
contract. Should live next to MCP server docs and be linked from
`base/app/Mcp/ErrorCode.php` header comment.

**Estimate: 30 min**

---

## How to pick up Phase 3

1. Read this doc.
2. Pick one of 3.1 / 3.2 / 3.3 (they are independent).
3. Create a branch: `feat/phase3-<scope>`.
4. Follow the acceptance criteria.
5. Run `/qa` + `/security-review` per sprint-orchestrate skill.
6. When all 3 are done, delete this doc + drop the CLAUDE.md reference.

---

## Cross-references

- Original research: `../../../claudedocs/research_grpc_mcp_transport_2026-04-22.md`
- Phase 1 design: `../../../docs/design-mcp-observability-sprint.md`
- Phase 1 architecture: `../../../docs/architecture-mcp-observability-phase1.md`
- Serena memory: `mcp/phase3-deferred-work` (read with
  `mcp__serena__read_memory("mcp/phase3-deferred-work")`)
