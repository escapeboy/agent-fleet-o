# Changelog

All notable changes to Agent Fleet Community Edition are documented here.

## [1.24.0] - 2026-05-03

Five borrowable patterns from the Trendshift research sweep ([`claudedocs/research_trendshift_borrowable_2026-05-03.md`](https://github.com/escapeboy/agent-fleet/blob/master/claudedocs/research_trendshift_borrowable_2026-05-03.md) in the parent repo) — each one shipped as a small, opt-in addition rather than a sweeping refactor.

### Added — A-RAG hierarchical retrieval

Inspired by the [A-RAG paper (arXiv 2602.03442)](https://arxiv.org/abs/2602.03442). Two new MCP tools complement the existing semantic `memory_search`:

- **`memory_keyword_search`** — exact-term retrieval via the existing PostgreSQL full-text `content_tsv` column (with `ts_rank` ordering and SQLite `ilike` fallback for tests). Use when the agent needs passages mentioning specific terms verbatim.
- **`memory_chunk_read`** — fetch a memory by id with optional ±N adjacent chunks within the same `topic`+`agent` partition. Pairs naturally with the search tools so the agent can pivot between granularities mid-trace.

### Added — Aider-inspired commit discipline

- **`GitRepository.commit_discipline = atomic`** activates a new `AtomicCommittingGitClient` decorator that rewrites every mutation's commit message via a weak LLM (haiku) into Conventional Commits format (capped 72 chars, type-prefixed). Falls back to the caller's message when the LLM is unavailable. Off by default; enable per repository.

### Added — Activepieces-inspired auto-MCP for connectors

- **`AutoRegistersAsMcpTool` contract** — connectors / drivers / sources can opt in once and be auto-exposed as MCP tools. The platform's `ConnectorMcpRegistrar` discovers tagged services at boot and synthesizes a per-connector `Tool` subclass under `bootstrap/cache/synthetic-mcp-tools/` (PSR-4 autoloaded). Hand-written tools always win on name collision; existing 485+ tools keep working unchanged.
- **3 opt-in proofs** this release: `signal.rss.poll`, `signal.webhook.ingest`, `signal.webclaw.scrape`.
- **`php artisan mcp:cache-connector-tools` / `--clear`** — manage the cache.

### Added — Self-healing browser harness

Inspired by [browser-use/browser-harness](https://github.com/browser-use/browser-harness). New built-in tool kind:

- **`BuiltInToolKind::BrowserHarness`** + `BrowserHarnessHandler` — runs a CDP-driven Chrome inside the existing `DockerSandboxExecutor`. The agent provides a task and (optionally) a Python helpers diff appended to a `helpers.py` for that run.
- **`browser_harness_run` MCP tool** — single entry point for agents.
- **Persisted helpers gate** — with `persist_helpers: true`, the diff is staged on a linked Toolset (`browser_helpers_pending_review = true`) for human review before becoming approved. Pairs with the trajectory→skill extractor shipped in 1.23.

### Added — Kestra-inspired Workflow YAML Git Sync

- **One-way `Workflow` → `GitRepository`** sync via the new `WorkflowGitSync` model. Save a workflow → `WorkflowSaved` event → debounced 60s → `PushWorkflowYamlJob` writes `workflows/{slug}.yaml` to the configured branch via the existing `GitClientInterface`. Failures surface as `UserNotification` after 3 retries.
- **`workflow_export_yaml` / `workflow_import_yaml` MCP tools** — round-trip a portable v2 envelope (with checksum + fuzzy reference hints) over the existing `ExportWorkflowAction` / `ImportWorkflowAction`.
- Reverse direction (PR-merge → import) is documented as P2.

### Migrations (3)

- `2026_05_03_110000_add_commit_discipline_to_git_repositories`
- `2026_05_03_120000_add_browser_helpers_to_toolsets`
- `2026_05_03_130000_create_workflow_git_syncs_table`

### MCP — 8 new tools

`memory_keyword_search`, `memory_chunk_read`, `signal.rss.poll`, `signal.webhook.ingest`, `signal.webclaw.scrape`, `browser_harness_run`, `workflow_export_yaml`, `workflow_import_yaml`. Total tool count: **493+**.

### Infrastructure

- **`base/packages/fleetq-boruna-audit/`** — vendored locally so standalone base CI can install (was previously a `../../packages/...` path repo that only resolved inside the parent monorepo). Parent monorepo continues to use its own copy.
- **PHPStan baseline** — third baseline file `phpstan-baseline-trendshift.neon` adds 16 patterns for pre-existing errors that surfaced when the composer.lock was refreshed (Hermes-sprint code; not behavioral).

### Tests

46 new tests, 117 assertions: `MemoryHierarchicalRetrievalTest`, `AtomicCommitTest`, `AutoConnectorMcpTest`, `BrowserHarnessHandlerTest`, `WorkflowYamlGitSyncTest`.

## [1.23.0] - 2026-04-27

Three research-driven sprint arcs and a four-sprint governance build landed in this release.

### Added — Real-World Action governance (Offsite-inspired arc)

- **ActionProposal foundation** — polymorphic `action_proposals` table backs a generalized proposal flow. Assistant tool calls, integration writes, and git operations now route through the same approve/reject pipeline. `target_type` discriminator covers `tool_call`, `integration_action`, and `git_push` in v1; the schema is open for additional types.
- **Auto-execute on approval** — approving an `ActionProposal` dispatches `ExecuteActionProposalJob` (queued, idempotent). The executor re-runs the gated operation with a container-binding bypass so re-execution doesn't loop back into another proposal. Failure path captures `execution_error` and transitions to `ExecutionFailed`.
- **Per-tier risk policy** — every team has `team.settings.action_proposal_policy = {low, medium, high}` mapping each tier to `auto`/`ask`/`reject`. Tier→risk mapping: read=low, write=medium, destructive=high. Legacy `slow_mode_enabled=true` is preserved as `{high: 'ask'}`. Each proposal record stores the actual computed `risk_level` for audit.
- **`IntegrationActionGate`** — single chokepoint inside `ExecuteIntegrationActionAction::execute()` covers all 50+ integration drivers. Heuristic verb classifier (22 high-risk verbs, 21 medium, rest low). Three callers (`IntegrationController::execute`, `IntegrationExecuteTool`, `IntegrationManageTool`) catch the new exceptions and surface coherent "proposed" / "refused" responses.
- **`GitOperationGate`** — `GitOperationRouter::resolve()` wraps every `GitClientInterface` (GitHubApi, GitLabApi, Sandbox, Bridge) in a `GatedGitClient` decorator. All 27 MCP `GitRepository/*` tools inherit the gate transparently. Explicit per-method risk map (read=low, branch/commit/push/writeFile=medium, PR-lifecycle/dispatch/release=high; unknown methods default to **high** as a safe default).
- **Unified Real-World Actions inbox** — the `/approvals` page now shows pending `ActionProposals` and outbound `ApprovalRequests` in one time-sorted list when `activeView=actions`. Read-side union — no shadow rows, no migration. Tab badges sum both sources.
- **Conversation result append** — when a proposal originates from an assistant conversation, the execution outcome is appended to that conversation as an `assistant`-role message, closing the visibility loop for the user who asked.

### Added — Public discovery endpoint

- **`GET /.well-known/fleetq`** — public, unauthenticated capability manifest. Lets external AI tools (Cursor, Codex, Claude Code, OpenCode) one-shot discover the MCP HTTP/stdio endpoints, REST API base, OpenAPI URL, auth scheme, and tool count. Each block is gated by a `discovery.expose_*` config flag so operators can scrub the public surface.
- **`system_discovery_get` MCP tool** — same payload as the HTTP endpoint, callable from inside an MCP session.
- Throttled to 60 req/min per IP, no auth required, no CSRF (read-only).

### Added — Live team graph

- **`/team-graph` page** — Cytoscape.js force-directed visualization of agents, humans, and crews. Real-time updates via Reverb WebSockets (Echo with `wire:poll.5s` fallback). Shape semantics: agents=rect, humans=ellipse-with-initials, crews=hexagon.
- **`TeamActivityBroadcast` event** — emitted from `BroadcastAgentExecuted` and `BroadcastExperimentTransitioned` listeners.
- **`team_graph_get` MCP tool** — programmatic access to the same graph data.
- **Reverb infrastructure** — new `reverb` Docker service, `laravel-echo` + `pusher-js` wired into both parent and base `package.json` (dual rule).

### Added — Lightfield-inspired arc

- **Citations on assistant responses** — when the assistant calls a `*_get` MCP tool, the tool result is captured and the assistant's reply renders inline citations linking back to the source entity.
- **CSV migration agent** — drop a CSV onto a project; the agent infers schema, suggests a destination, and migrates row-by-row with progress and rollback.
- **World-model digest** — daily summary of what the platform knows: signal counts by intent, top contacts by engagement, agent activity, budget burn-down.
- **Signal intent classification** — every inbound signal gets a Haiku-classified `intent` tag (Question / Bug / FeatureRequest / Spam / Other). Searchable, filterable, surfaced in `signal_list`.
- **Selection context for assistants** — Livewire pages can mark items "selected"; the assistant receives the selection as part of context. Implemented via `HasAssistantSelection` trait — bulk-select pattern for any list page.

### Added — Pydantic-inspired arc (14 sub-sprints)

- **Per-team OTLP observability** — teams can BYO OpenTelemetry collector endpoint. Cloud UI, queue tracer, test-connection probe, last-probe badge.
- **Eval pipeline** — curate test cases from real runs, replay them against alternative configurations, compare outputs side-by-side. Cycle: real run → curate → replay → diff → promote.
- **Output schema enforcement on Agent + Skill** — define a JSON schema; runs that don't match get retried with a corrective system prompt (configurable retry budget). UI editor for the schema. Schema fingerprint propagates through `LlmRequestLog` for analytics.
- **World-model visibility page** — `/world-model` shows what the platform "knows" about the team's workspace (entities, relationships, KG facts).
- **Bulk-select pattern** — `HasAssistantSelection` trait + UI checkbox column on list pages, bridge to assistant context.

### Added — Boruna integration

- **3-sprint integration build** delivering bidirectional contact sync, structured intake forms, and shared signal taxonomy with the Boruna platform.

### Added — Self-service troubleshooting

- **6 sprints** of UI affordances: live diagnostic panels for agents, skills, tools, projects, integrations, and tokens. Each "why isn't this working?" question now has an in-product answer.

### Added — Polish

- **Vendor badges** on agents (`<x-agent-vendor-badge>` Blade component) — 9 vendors, monogram or FA icon, surfaced on the agent detail and list pages.
- **`InjectTeamContext` middleware** — runs between memory and KG retrieval in the agent execution pipeline, so every agent run sees a fresh "what's currently going on in this team" preamble.

### Fixed

- **CRITICAL — `LocalAgentDiscovery::vpsBinaryPath()` silently fell through to `which claude`** when `local_agents.vps.binary_path` was set but non-executable. On dev/CI machines with `claude` installed system-wide, the explicit-but-broken config was masked and the missing-binary throw never fired. Fix: early-return null when `binary_path` is configured but unusable; `which` only runs when no path is configured at all.
- **Experiment iteration off-by-one** — `RunEvaluationStage::handleIterate` increments `current_iteration` BEFORE the Iterating transition, so `enforceIterationLimit` must use `>` not `>=`. With `>=`, experiments could never run their last legitimate iteration. Surfaced after the `AiRequestDTO::userId` sweep removed the cover.
- **`AiRequestDTO` `userId` was sometimes null** — `LocalAgentGateway::executeVps` does `User::find($request->userId)`, so null silently denied super-admins access. Full sweep on 2026-04-26: every action that resolves a provider via the hierarchy (skill → agent → team → platform) now passes a usable `userId`. Pattern: `$userId ?? Team::ownerIdFor($teamId)` (new static helper).
- **BYOK embeddings via `EmbeddingService::embedForTeam`** — calling `Prism::embeddings()->using('openai', ...)` directly does NOT honor team `TeamProviderCredential`. New helper applies team BYOK key like `PrismAiGateway::applyTeamCredentials` does for chat. Migrated `CloudRetrieveRelevantMemoriesAction` + `StoreMemoryAction`.

### Security

- **Audit trail for integration actions** — `IntegrationActionExecuted` event fires after every driver call, mapped through `OcsfMapper::integration.*`.
- **Identity card auto-ping** — integration detail page caches `meta.account` from extended `HealthResult::ok($latency, $message, $identity)`. Surfaces account name/handle so operators see *which* account they connected, not just whether the credential is valid.
- **URL XSS hardening** — driver-supplied profile URLs (Twitter, Discord, Google, PagerDuty, Klaviyo, GitHub, Slack, Linear, GitLab) gated through `^https?://` allowlist before render.
- **Latent `ArgumentCountError`** in 5 drivers (Twitter/Discord/Google/PagerDuty/Klaviyo) fixed as side-effect of the identity overhaul.

### Infrastructure

- `GitOperationRouter` resolves a `GitClientInterface`, then wraps it in `GatedGitClient` unconditionally. The wrapper is a no-op when `app('git_gate.bypass')` is bound truthy.
- `IntegrationActionGate` and `GitOperationGate` share the same per-tier policy resolver shape (`team.settings.action_proposal_policy` with legacy `slow_mode_enabled` fallback).
- `ActionProposalExecutor` gains `executeIntegrationAction()` and `executeGitPush()` branches with try/finally bypass-clear discipline.
- `AssistantToolRegistry` slow-mode wrap (`wrapToolsWithSlowModeGate`) hooks both call sites in `SendAssistantMessageAction` after `getTools($user)` resolution.
- `package.json` now declares `laravel-echo` and `pusher-js` (dual rule with parent).

### Stats

- 2869 tests pass (zero failures, 11 213 assertions)
- 485 MCP tools registered on `AgentFleetServer`

## [1.22.0] - 2026-04-22

### Added

- **Structured MCP error codes** — every MCP tool now returns gRPC-canonical error codes (`UNAVAILABLE`, `PERMISSION_DENIED`, `RESOURCE_EXHAUSTED`, `DEADLINE_EXCEEDED`, `INVALID_ARGUMENT`, `FAILED_PRECONDITION`, `NOT_FOUND`, `INTERNAL`) with `retryable` hint and optional `retry_after_ms`. Agents (Claude, Codex, Cursor) now know exactly when to retry vs. fail fast, eliminating blind retry loops that burn budget.
  - New `App\Mcp\ErrorCode` enum with `isRetryable()` + `httpStatus()` helpers
  - New `App\Mcp\ErrorClassifier` service maps 15+ exception classes to canonical codes; includes `classNameHint()` substring fallback for cloud-only exceptions without a hard dependency
  - New `App\Mcp\Concerns\HasStructuredErrors` trait with 8 typed helpers (`notFoundError`, `permissionDeniedError`, `invalidArgumentError`, `failedPreconditionError`, `resourceExhaustedError`, `unavailableError`, `deadlineExceededError`, `errorResponse`)
  - **321 of 464 non-Compact MCP tool files retrofitted** across 32 domains (Agent, Experiment, Project, Workflow, Crew, Shared, Skill, Tool, Email, Chatbot, Signal, Admin, Approval, Artifact, Assistant, Auth, Boruna, Bridge, Budget, Compute, Credential, Evaluation, Evolution, FounderMode, GitRepository, Integration, Marketplace, Memory, Outbound, Profile, RunPod, System, Telegram, Testing, Trigger, Webhook, Website). Remaining 143 files had no `Response::error()` calls to retrofit.
  - Central wrapper in `CompactTool::handle()` catches any unhandled exception and classifies via `ErrorClassifier` — so even non-retrofitted tools get structured responses.
- **MCP tool deadlines** — every MCP tool schema gains an optional `deadline_ms` parameter. Agents can bound wall-clock time per call. Enforcement checkpoints in `WorkflowGraphExecutor`, `CrewOrchestrator`, `ExperimentStateMachine`, and `LocalToolLoopExecutor`. New `App\Mcp\DeadlineContext` singleton (request-scoped, nested calls inherit). Minimum 100ms clamp, maximum 10-minute cap (security hardening from review).
- **OpenTelemetry distributed tracing** — opt-in via `OTEL_ENABLED=true`. OTLP HTTP exporter, zero overhead when disabled (NoOp tracer). Three span layers: `mcp.tool.{name}` (server) → `ai.gateway.{complete,stream}` (client) → `llm.provider.{name}` (client) with token/latency/fallback attributes. Graceful fallback when exporter unreachable.
  - New `observability` Docker Compose profile — `docker compose --profile observability up -d` starts Jaeger all-in-one (UI on :16686, OTLP HTTP on :4318)
  - New `config/telemetry.php` with `redacted_attributes` list + `AttributeRedactor` service for future span-attribute sanitization
  - Packages: `open-telemetry/sdk` ^1.14, `open-telemetry/exporter-otlp` ^1.4
- **SSE client-disconnect handling** — `ChatController::sendMessageStream` and `OpenAiCompatibleController` streaming paths now throw `App\Exceptions\ClientDisconnectedException` when `connection_aborted()` detects a closed socket. Gateway `stream()` unwinds cleanly — no more wasted LLM tokens after a user closes their tab.

### Fixed

- **SECURITY — SSRF validation misclassified as retryable INTERNAL.** `SsrfGuard`, `LocalLlmUrlValidator`, and cron validators throw plain `\InvalidArgumentException`, which was falling through to `ErrorCode::Internal` with `retryable=true`. Agents would retry with malicious URLs, silently hiding SSRF attack signals from auditing. Fix: added `\InvalidArgumentException` to `ErrorClassifier::EXCEPTION_MAP` → `ErrorCode::InvalidArgument` (retryable=false). Removed 5 no-op `catch (\InvalidArgumentException $e) { throw $e; }` blocks (central wrapper now classifies directly).
- **ConnectionException + ModelNotFoundException message scrubbing.** Previously `$e->getMessage()` leaked internal topology (host:port, DNS names, FQN + primary-key shape) in error responses. Now both exception types return generic safe strings while preserving the canonical error code for retry logic.

### Infrastructure

- `AppServiceProvider` now registers `ErrorClassifier`, `DeadlineContext`, `TracerProvider`, and `AttributeRedactor` as singletons. Terminating callback clears `DeadlineContext` so Horizon workers don't inherit stale deadlines from exception paths.
- `docker-compose.yml` gains `observability` profile with Jaeger all-in-one (opt-in).

### Phase 3 work documented for continuity

Queue-job deadline propagation, expanded OTel spans (DB/cache/outbound/semantic-cache + W3C traceparent through Redis), and SSE explicit buffer caps are deferred to a future release. See `docs/mcp-observability-phase3-todo.md` (canonical), `CLAUDE.md` banner, and Serena memory `mcp/phase3-deferred-work`.

### Stats

- 175 MCP tests (Unit + Feature) pass — zero regressions introduced
- Full suite: 2001 passed across 4 refactor phases (Phase 1/2/2b/2c)
- Security review: 1 blocker + 3 NEEDS_WORK items identified and fixed before merge

## [1.21.0] - 2026-04-18

### Added

- **Founder Mode pack** — platform-owned marketplace bundle of 6 persona agents (Strategist, Product Lead, Growth Hacker, Finance Advisor, Ops Manager, Risk Officer), 20 framework skills covering product/growth/finance/ops/testing methodologies (RICE, SPIN, BANT, MEDDIC, OKRs, Bullseye, Lean Startup, Shape Up, Unit Economics, Kano, TAM-SAM-SOM, K-Factor, Cash Flow, NPV-IRR, RACI, Lean Ops, A/B Testing, 3-Day MVP, OWASP, Bessemer), and 5 pre-built workflows. New `Framework` enum (20 cases) + `FrameworkCategory` (6) on `skills.framework`. `DeliverableType` enum (8 cases: ExecutiveReport/ActionPlan/ResearchBrief/Forecast/Pitch/ContentPiece/TechnicalSpec/Template) on `artifacts.deliverable_type` with typed Blade partials. `/frameworks` Livewire browser. 3 MCP tools (`framework_list`, `founder_mode_status`, `founder_mode_install`).
- **Bidirectional widget comments for bug reports** — reporters and agents can now exchange comments through the public JS widget. New public endpoints: `GET /api/public/widget/bug-reports` (list with optional `?project=` filter), `GET /api/public/widget/bug-reports/{signal}/comments`, `POST /api/public/widget/bug-reports/{signal}/comments`. New `CommentAuthorType` enum (`human/agent/reporter/support`) with `isWidgetVisible()` helper. `signal_comments.widget_visible` column + partial index. Admin reply defaults to `support` type (visible to reporter) with opt-in downgrade to `human` (internal only). Reporter name shown in admin UI from `signal.payload.reporter_name`. `unread_comments_count` exposed via `withCount`. `SignalCommentAdded` event.
- **Structured intake for widget bug reports (opt-in)** — `bug_report_project_configs` table allows per-project configuration of required fields and intake workflow. MCP tools: `bug_report_project_config_get`, `bug_report_project_config_update`.
- **AI risk scanning for Marketplace listings** — automatic risk assessment before publish, exposed in `marketplace_browse` MCP results.
- **MCP coverage audit gap fixes** — `signal_get` now exposes `metadata`; `bug_report_detail` exposes `ai_extracted`; `marketplace_browse` exposes `risk_level`; `agent_list`/`agent_get` expose `scope` and `owner_user_id`; `agent_list` adds `scope` filter; new `bug_report_delete` tool; new `bug_report_project_config` get + update tools.
- **Configurable `VERIFIED_EMAIL_PROVIDERS`** — comma-separated env var (default: `gmail.com,outlook.com,yahoo.com,...`). Controls which OAuth email domains qualify for auto-link.
- **Bug report list** — delete button added to admin list page.

### Fixed

- **Bug report detail** — only the first attachment was rendered; now renders all attachments in the media collection.
- **Widget bug-report list** — `unread_comments_count` was always 0 (missing `withCount`); now returns real counts.
- **InsightsPage** — team scoping via `whereHas`, correct stage column names, correct deduction type for spend calculations.
- **AiControlCenterPage** — `circuit_breaker_states` query was broken; fixed column reference.
- **StageType enum** — cast to `->value` in Insights Blade template to prevent `Object of class StageType could not be converted to string`.
- **Sidebar** — light-bulb icon now registered in `sidebar-link.blade.php` for the Insights nav item.

### Security

- **CRITICAL — OAuth account takeover via unverified email auto-link.** `SocialAccountService::handleCallback()` step 4 auto-linked any OAuth account whose email matched an existing user without verifying the provider was trustworthy. Attacker could use a provider that hands out unverified emails (e.g. a custom OAuth provider) to hijack any account. Guard added: auto-link only runs when the OAuth provider is on the `verified_email_providers` list. Configurable via `VERIFIED_EMAIL_PROVIDERS` env.
- **Prompt injection guard in chatbot memory context.** User-controlled content (agent name, memory tags) was interpolated unsanitized into the LLM context string. Strip to printable ASCII + truncate applied before interpolation.
- **IDOR fix in chatbot memory context provider.** Memory lookup was missing team-scope check; fixed with explicit `where('team_id', ...)`.

## [1.20.0] - 2026-04-14

### Added

- **Public JS widget endpoint** — `POST /api/public/widget/bug-report` accepts bug reports from any domain without CORS per-domain configuration. Auth is via `team_public_key` in the request body (no `Authorization` header) enabling `Access-Control-Allow-Origin: *`. Rate-limited to 30 requests/minute per team.
- **Widget public key management** — every team is issued a unique `wk_`-prefixed key on creation (backfilled for existing teams). Visible in Team Settings with a one-click copy button and a rotate button (owner/admin only).
- **Migration** — `add_widget_public_key_to_teams_table` (unique, nullable, backfill via `lazyById()`).
- **8 feature tests** in `BugReportWidgetTest` covering: submission without auth header, invalid key rejection, field validation, severity enum, key generation, key rotation, no-auth-header pass, and team scoping.

## [1.19.0] - 2026-04-14

### Added

- **Bug Report Signal System** — lightweight QA reporting pipeline for teams using an external JS widget:
  - `POST /api/v1/signals/bug-report` multipart endpoint accepts 15 fields: title, description, severity, URL, reporter, screenshot (PNG/JPG/WebP, 10 MB), optional attachment, action log, console log, network log, browser, viewport, environment, project key.
  - `BugReportConnector` — new input connector (`driver=bug_report`) that parses JSON log strings, applies severity tags, and stores screenshot + attachments to the `bug_report_files` media collection.
  - **Signal status lifecycle** — 8-state machine (`received → triaged → in_progress → delegated_to_agent → agent_fixing → review → resolved / dismissed`) with `SignalStatusTransitionMap`, `UpdateSignalStatusAction`, `SignalStatusChanged` event.
  - **Signal comments** — `signal_comments` table (`SignalComment` model, `AddSignalCommentAction`). Human and agent comments tracked separately.
  - **Agent delegation** — `DelegateBugReportToAgentAction` creates an Experiment with full bug context (title, description, console errors, action log, screenshot URL). `SyncSignalStatusOnExperimentComplete` listener advances signal to `review` when the experiment reaches `Completed`.
  - **Dashboard UI** — `BugReportListPage` (filters: project, severity, status, reporter; sortable columns) + `BugReportDetailPage` (screenshot lightbox, collapsible action/console/network logs via Alpine.js, threaded comments, status controls, Delegate to Agent button). Routes: `GET /bug-reports` + `GET /bug-reports/{signal}`.
  - **4 MCP tools** — `bug_report_list`, `bug_report_detail`, `bug_report_update_status`, `bug_report_add_comment` registered in `AgentFleetServer`.
  - **In-app notifications** — critical severity triggers immediate owner/admin notification on ingestion; status → `review` notifies owner/admin that agent fix is ready.
  - **Retention cleanup** — `signals:cleanup-bug-reports` command (daily at 03:00) deletes signals older than `team.settings.bug_report_retention_days` (default 90) and clears media.
  - **Migrations** — `add_status_project_key_to_signals_table`, `create_signal_comments_table`.
  - **14 feature tests** — `tests/Feature/Domain/Signal/BugReportSignalTest.php` covering submission, validation, auth, status transitions, invalid transition exception, comments, and tenant scoping.

### Fixed

- **Screenshot media collection mismatch** — `BugReportConnector` was previously routing uploaded files through `IngestSignalAction`'s generic `attachments` collection. Screenshots and attachments now stored directly to `bug_report_files` collection so detail view and MCP tool screenshot URLs are non-empty.
- **`additional_file` unrestricted MIME type** — added `mimes:png,jpg,jpeg,webp,gif,pdf,txt,log,json,zip,csv` restriction to prevent executable file uploads.
- **`SyncSignalStatusOnExperimentComplete` TeamScope bypass risk** — switched to `Signal::withoutGlobalScopes()` in the queue-listener context, consistent with all other signal-querying listeners (`EvaluateTriggerRulesJob`, `ExtractKnowledgeEdgesJob`, etc.).

## [1.18.0] - 2026-04-10

### Added

- **Website Builder — feature complete across 5 sprints.** The 1.17.0 website builder MVP is now production-ready:
  - **Assistant parity** — 11 new AI tools + 4 read tools in `WebsiteMutationTools`, `ListEntitiesTools`, `GetEntityTools`. In-app assistant can create, update, publish, unpublish, delete, and generate websites end-to-end.
  - **Unpublish path** — `UnpublishWebsiteAction`, `UnpublishWebsitePageAction`, REST, MCP (`website_unpublish`, `website_page_unpublish`), UI buttons.
  - **Deployment pipeline** — `WebsiteDeploymentDriver` contract, `ZipDeploymentDriver` (7-day signed URL), `VercelDeploymentDriver` (credential-scoped, log scrub), `DeployWebsiteAction`, `DeployWebsiteJob`, REST (`POST /api/v1/websites/{id}/deploy`), MCP (`website_deploy`, `website_deployment_list`), Livewire Deploy buttons, deployments table.
  - **Dynamic content** — `EnhanceWebsiteNavigationAction` auto-runs on publish/unpublish/delete/rename. `rewriteInternalLinks()` rewrites broken `<a href="/...">` to `/`. `WebsiteWidgetRenderer` expands `<!-- fleetq:recent-posts -->` and `<!-- fleetq:page-list -->` at serve time with Redis caching, `content_version` invalidation, per-render memoization, 20-widget hard cap.
  - **Widget cache metrics** — `WebsiteWidgetMetrics` service with 24h per-widget counters and `snapshot()` API.
  - **AI prompt vocabulary** — `GenerateWebsiteFromPromptAction` now documents widgets so AI-generated sites use them by default.
- **15 MCP website tools** registered in `AgentFleetServer`: list/get/create/update/delete (website + page), publish/unpublish (website + page), generate, deploy, deployment_list, export, analytics.
- **Migrations** — `add_url_and_started_at_to_website_deployments_table`, `add_content_version_to_websites_table`.
- **HtmlSanitizer unit tests** — `tests/Unit/Domain/Website/HtmlSanitizerTest.php` (15 tests covering XSS, form handling, HTML5 semantic tags, event handler stripping, CSS sanitization, malformed input).
- **Dynamics feature tests** — `tests/Feature/Website/WebsiteDynamicsTest.php` (30 tests covering re-enhance hooks, link validator edge cases, widget rendering, widget caching, observer version bumps, widget metrics, AI prompt lock-in).
- **Throttle assertion test** — `tests/Feature/Website/PublicSiteControllerThrottleTest.php` (3 tests verifying `throttle:10,1` middleware actually fires on the 11th form submit; isolated from full-suite flakiness via monotonic unique IPs and multi-store cache flush).

### Fixed

- **CRITICAL — `ezyang/htmlpurifier` composer dep missing.** `HtmlSanitizer::purify()` has always called `HTMLPurifier_Config::createDefault()` but the package was never in `composer.json`. Every page save was silently crashing. Dependency added; sanitization actually works now.
- **CRITICAL — `TeamScope` bypassed in the entire test suite.** `phpunit.xml` was missing `force="true"` on the `APP_ENV=testing` env var. `Application::runningUnitTests()` returned false → `TeamScope::apply()` early-returned → zero tenant scoping in tests since day 1. Fix: force the env var in phpunit.xml AND add `defined('PHPUNIT_COMPOSER_INSTALL')` defence-in-depth in `TeamScope` itself. Production always enforced the scope correctly; this only fixes the test harness.
- **HIGH — `EnhanceWebsiteNavigationAction::injectContactForm` phishing bypass.** Loose `stripos` check could be defeated by a page mentioning `/api/public/` in unrelated text plus a phishing form. Tightened to a regex matching the `<form action="/api/public/sites/{slug}/forms/{id}">` pattern directly.
- **MEDIUM — Widget DoS from unbounded widget count per page.** `MAX_WIDGETS_PER_PAGE = 20` hard cap + per-render memoization.
- **MEDIUM — Observer N+1 during bulk enhance.** `EnhanceWebsiteNavigationAction` wraps its loop in `WebsitePage::withoutEvents()` and issues a single atomic `content_version` bump at the end.
- **MEDIUM — `strtok` global state leak** in `rewriteInternalLinks` → replaced with `explode`.
- **LOW — Vercel error body verbatim in logs.** `VercelDeploymentDriver::scrubResponseBody()` truncates to 500 chars and redacts token/key/Bearer patterns.

### Changed

- **`HtmlSanitizer`** allows `<!-- fleetq:... -->` comments via `HTML.AllowedCommentsRegexp` so widget markers survive Phase 1 sanitization. Other comments still stripped.
- **`PublicSiteController::page()`** invokes `WebsiteWidgetRenderer::render()` on exported HTML before returning. Widget queries use `withoutGlobalScopes([TeamScope::class])` + explicit `where('team_id', ...)` for safe public serving without an auth user.
- **`AssistantToolRegistry::toolTier()`** classifies `publish_`, `unpublish_`, `deploy_` prefixes as write-tier.
- **`MutationTools::writeTools()`** + `destructiveTools()` include `WebsiteMutationTools`.
- **`ListEntitiesTools::tools()`** adds `list_websites` and `list_website_pages`.
- **`GetEntityTools::tools()`** adds `get_website` and `get_website_page`.
- **`WebsiteDetailPage` Livewire component** gains Deploy buttons (ZIP + Vercel), Unpublish buttons (site + per-page), deployments table section.
- **Pint housekeeping** — `ExportWebsiteController`, `CreateFlowEvaluationDatasetAction`, `ToolTranslator`, `EnhanceWebsiteNavigationAction` formatting drift fixed. Full repo is `pint --test` clean.

### Security

- Form-action phishing bypass closed.
- Widget DoS hard-capped.
- Vercel credential log scrub.
- Tenant isolation active in tests for the first time ever.

### Tests

- **1796 passing**, 0 failing, 1 risky, 10 skipped. Up from ~1630 in 1.17.0. Includes full regression coverage for every security finding across the 5 sprints.

## [1.17.0] - 2026-04-02

### Added

- **GPU Tool Templates** — Pre-configured GPU tool templates with 1-click deploy to compute providers (RunPod, etc.). 16 templates across 8 categories: OCR (GLM-OCR), STT (Whisper), TTS (XTTS, Kokoro, F5-TTS), Image Gen (SDXL, FLUX.1), Video Gen (Wan2.1), Embedding (BGE-M3), Code Execution (Qwen2.5 Coder, Mistral 7B), and more. New `ToolTemplate` model, `DeployToolTemplateAction`, `ToolTemplateCatalogPage` UI at `/tools/templates`, `ToolTemplateManageTool` MCP tool, and `ToolTemplateController` REST API (`GET /api/v1/tool-templates`, `POST /api/v1/tool-templates/{id}/deploy`).
- **MCP Marketplace** — Browse, search, and install MCP servers from the Smithery registry (300+ servers). `McpRegistryClient` queries `registry.smithery.ai` with caching. `McpMarketplacePage` UI at `/tools/marketplace` with server cards, verified badges, and install modal. SSRF protection via `isPrivateHost()`, command whitelist for install safety, URL sanitization at data layer.
- **Apify Integration** — Full native connector with 6 actions: `run_actor` (with memory/wait caps), `get_run`, `get_dataset`, `search_store`, `list_actors`, `get_actor_info`. Webhook verification via `x-apify-webhook-secret` header. URL parameters use `urlencode()` to prevent path injection.
- **1Password Integration** — Two integration paths: (1) MCP Tool — `@takescake/1password-mcp` added to PopularToolsSeeder with vault_list, item_lookup, password_read/create, password_generate. (2) Native Driver — `OnePasswordIntegrationDriver` with list_vaults, search_items, get_item (redacted fields), resolve_secret (masked output only). SCIM filter injection prevention, alphanumeric ID validation.
- **Screenpipe Integration** — Local screen & audio capture connector. MCP Tool (`npx screenpipe-mcp`) with search_content and export_video. Signal Connector (`ScreenpipeConnector`) polls screenpipe REST API for OCR + audio content with time-based cursor dedup. Loopback-only SSRF protection.
- **Quick Agent** — Markdown-based agent creation inspired by screenpipe's pipe.md pattern. Write a prompt with optional YAML frontmatter (role, goal, tone, style) and the body becomes the agent's backstory. Optional schedule creates a continuous Project automatically. UI at `/agents/quick`.
- **Integrations UX Overhaul** — Category tabs with counts, descriptions for all 55+ integrations, auth type badges, `credential_fields` config for drivers without full driver classes. 11 new integrations added (Resend, SendGrid, OpenAI, Anthropic, Replicate, Pinecone, Firebase, AWS, Cloudflare, n8n, GitHub Actions).
- **Tools Card Grid** — `/tools` page converted from table to responsive card grid (4 columns on xl screens). Cards show name, toggle, type/platform/risk badges, description, function count, agent count.
- **Marketplace Seeder** — 34 official marketplace listings (22 skills + 12 agents) across 6 categories. All free, public, `is_official`. Usage stats start at 0 (no hardcoded fake data).

### Fixed

- `/app/marketplace` 502 Bad Gateway — nginx `location /app/` was catching all `/app/*` paths for Reverb WebSocket proxy. Fixed by changing marketplace URL prefix to `/hub`.
- Flash message showing stale variable after modal close in integrations and tool template pages.
- LIKE wildcard injection in template search (`%` and `_` now escaped).
- `isEmpty()` called on array instead of collection in integration list page.

### Security

- Screenpipe connector restricted to loopback URLs only (localhost/127.0.0.1/::1) — prevents SSRF.
- MCP Marketplace: SSRF protection via `isPrivateHost()`, command whitelist (`npx`, `uvx`, `node`, `python3`, `docker`, `bunx`), shell metacharacter rejection, `sanitizeUrl()` strips non-HTTPS URLs from external data.
- Apify: `urlencode()` on URL parameters, `min()` caps on memory/wait, fail-closed webhook verification, account info redacted from ping response.
- 1Password: Secret values never returned raw (masked preview only), SCIM filter injection prevented (quote/backslash rejection), path traversal prevented (alphanumeric ID validation).
- Provider field in QuickAgentForm restricted to allowlist validation.

## [1.16.0] - 2026-03-31

### Added

- **Tool Profiles** — Predefined toolset profiles (researcher, executor, communicator, analyst, admin, minimal) that restrict which MCP tool groups an agent can access. Configured via `config/tool_profiles.php`. Agents select a profile via the new `tool_profile` column; `ResolveAgentToolsAction` filters tools by group prefix and enforces `max_tools` cap. `tool_profile_list` MCP tool for discovery.
- **Per-Step Smart Model Routing** — Pipeline stages now route to cost-appropriate models automatically. Scoring and metrics collection use cheap models (Haiku/GPT-4o-mini/Flash), planning and building use expensive models (Sonnet/GPT-4o/Pro). Configured in `config/experiments.php` (`stage_model_tiers` + `model_tiers`). `ProviderResolver` extended with stage-tier resolution. ~47% cost reduction per experiment pipeline run.
- **Experiment Transcript Search** — Full-text search across experiment stage outputs. `searchable_text` column (PostgreSQL `tsvector` with GIN index) populated on stage completion. `search_experiment_history` Assistant tool with optional LLM summarization. `experiment_search_history` MCP tool. `experiments:backfill-search-text` artisan command for existing data. SQLite ILIKE fallback for tests.
- **Auto-Skill Creation from Experiments** — When an experiment completes with 5+ stages and no similar skill exists, a draft skill is auto-proposed encoding the procedure. `ProposeNewSkillFromExperimentAction` synthesizes a reusable skill prompt via Haiku. Daily cap per team (default 5) prevents cost abuse. Configurable via `config/skills.php` (`auto_propose`). Team notification on proposal.
- **Pipeline Context Compression** — Compresses preceding stage outputs when exceeding 30K tokens. Preserves head (first stage) and tail (last 2 stages) in full; middle stages are pruned to 500 chars then optionally LLM-summarized. Cached to avoid redundant LLM calls. `BaseStageJob::getPrecedingContext()` helper for stage jobs. Configurable via `config/experiments.php` (`context_compression`).

### Security

- Tenant isolation hardened in experiment search tools — join enforces `team_id` match between `experiment_stages` and `experiments` tables (defense in depth on top of `TeamScope`).
- LIKE wildcard injection prevented in SQLite search fallback.
- Daily cap on auto-skill proposals prevents LLM cost abuse.
- Context compression results cached (1h TTL) to prevent redundant LLM calls.

## [1.15.0] - 2026-03-30

### Added

- **Knowledge Sources Page** — New `/knowledge` route with `KnowledgeSourcesPage` Livewire component and sidebar entry under the Build section. Exposes the existing `Knowledge` domain to the UI: create named knowledge bases, assign them to a specific agent or make them team-wide, ingest documents as plain text (chunked and vector-embedded via `KnowledgeUploadPanel`), and delete knowledge bases. Cards display status, chunk count, linked agent, and last-ingested timestamp.
- **Experiment Worklog Tab** — New Worklog tab on `ExperimentDetailPage` surfaces `WorklogEntry` records created during experiment execution. Five entry types: `reference`, `finding`, `decision`, `uncertainty`, `output`. Each entry renders a type badge, content preview, and timestamp.
- **Experiment Uncertainty Signals Tab** — New Uncertainty Signals tab on `ExperimentDetailPage` surfaces `UncertaintySignal` records across all experiment stages. Each signal displays signal type, severity, description, and the affected stage.
- **AI Generate Crew from Prompt** — "AI Generate" button on `CreateCrewForm` opens a modal that calls `GenerateCrewFromPromptAction` to pre-fill crew name, description, process type, and quality threshold from a natural-language goal description.
- **Repository Map Multi-Select in Agent Forms** — `CreateAgentForm` and `AgentDetailPage` now include a multi-select for linked Git repositories (`git_repository_ids` in agent config). Selected repositories inject a repository map into the agent's context at execution time. Repository IDs are validated server-side against the team's own `GitRepository` records to prevent cross-tenant references.
- **Portkey AI Gateway Integration** — `TeamSettingsPage` now includes a Portkey AI Gateway configuration card. Teams can set a Portkey API key and optional virtual key, stored as a `TeamProviderCredential` with `provider='portkey'`. When configured, `PortkeyGateway` routes LLM calls through `api.portkey.ai/v1` for observability, caching, and cost tracking.

## [1.14.0] - 2026-03-26

### Added

- **Searxng Web Search Connector** — Self-hosted meta-search integration via `SearxngConnector` implementing `InputConnectorInterface`. `poll()` ingests search results as signals with SSRF guard for user-configured URLs. `search()` provides direct result fetching for agent use, skipping SSRF guard for operator-configured internal Docker hostnames. `searxng_search` MCP tool added. Config: `SEARXNG_URL` env var / `services.searxng.url`. Engines: Google, Bing, DuckDuckGo, Wikipedia. JSON-only API mode, rate limiter disabled for internal use.

### Fixed

- Bridge disconnect goroutine race condition — stale `conn` reference captured by agent dispatch goroutines caused frames to be sent on dead connections after reconnect. `sendFn` now uses `c.Send()` which dynamically resolves the current live connection.

## [1.13.0] - 2026-03-26

### Added

- **Autonomous Web Dev Pipeline** — Full end-to-end agentic software development cycle. New Git operation MCP tools: `git_pr_merge`, `git_pr_status`, `git_pr_close`, `git_workflow_dispatch`, `git_release_create`. `GitClientInterface` extended with `mergePullRequest`, `getPullRequestStatus`, `dispatchWorkflow`, `createRelease`, `closePullRequest`, `getCommitLog` — implemented in GitHub, GitLab, Sandbox, and Bridge clients.
- **Deploy Integration Drivers** — Three new integration drivers: `VercelIntegrationDriver` (deploy, get_deployment, list_deployments, cancel, rollback), `NetlifyIntegrationDriver` (trigger_build, get_deploy, list_deploys, cancel, publish), `SshDeployIntegrationDriver` (run_deploy, check_health, rollback via SSH). GitHub driver extended with `create_pr`, `merge_pr`, `dispatch_workflow`, `create_release` actions.
- **Web Dev Cycle Workflow Template** — Pre-built DAG workflow: plan → implement → test → fix-loop → lint → review → human approval → deploy. Seeded via `PlatformWorkflowsSeeder`.
- **Per-Call Working Directory** — `AiRequestDTO` gains a `workingDirectory` field. `LocalAgentGateway` now prefers the per-call value over global config in both direct-exec and bridge-exec modes (`executeViaBridge` + `streamViaBridge`). `ExecuteAgentAction` passes `agent.configuration['working_directory']` into both `executeWithTools()` and `executeDirectPrompt()` requests.
- **Pre-Execution Scout Phase** — New `PreExecutionScout` middleware runs a cheap lightweight LLM call (Haiku / GPT-4o-mini / Gemini Flash) before memory and KG injection to identify what specific knowledge the agent needs. Results are stored in `AgentExecutionContext::$scoutQueries` and consumed by `InjectMemoryContext` and `InjectKnowledgeGraphContext` for targeted retrieval instead of generic semantic search. Enable per-agent via `config['enable_scout_phase']` or globally via `AGENT_SCOUT_PHASE_ENABLED`. Disabled by default.
- **Domain-Specific QA Rubrics** — `crew.settings.task_rubrics` JSONB map allows per-task-type weighted evaluation criteria. `ValidateTaskOutputAction` keyword-matches the task title/description against rubric keys, falls back to `default`, and injects weighted criteria into the QA agent's system prompt. `criterion_scores` are captured per rubric dimension in `qa_feedback`.
- **Crew QA Rubric Validation** — `CreateCrewAction` and `UpdateCrewAction` validate `task_rubrics` at write time: max 10 rubric types, criterion names restricted to `[\w\s\-]+` (blocks prompt injection), descriptions capped at 500 chars, weights must be 0–1 numerics.
- **Step Budget Awareness** — Agent system prompt now includes an `## Execution Budget` section when `max_steps > 1`, instructing the agent to complete core work by 80% of its step budget and reserve remaining steps for summarising and delivering results.
- **Chatbot Knowledge Source Toggle** — `chatbot_knowledge_sources.is_enabled` boolean column allows individual knowledge sources to be enabled or disabled without deletion. `ChatbotResponseService` filters to enabled sources only when building RAG context. Toggle UI added to ChatbotKnowledgeBasePage.

### Fixed

- `PreExecutionScout` uses `ProviderResolver` to respect the BYOK credential hierarchy (skill → agent → team → platform) instead of hardcoding `anthropic`. Scout queries are capped at 200 chars and 5 queries to prevent prompt-injection amplification when prepended to embedding inputs.
- `host-bridge.php` `working_directory` hardened against path traversal: realpath validation, null byte stripping, and assertion that the resolved path is within an allowed prefix.
- `InjectKnowledgeGraphContext` fixed `array_filter` without callback (was incorrectly filtering non-empty strings).
- `ValidateTaskOutputAction::resolveRubric` applies `strtolower()` to rubric keys before `str_contains` match (previously uppercase keys never matched lowercased task text).

## [1.12.0] - 2026-03-26

### Added

- **Crew Task Dependency Graph** — Tasks in a Crew execution can now declare explicit dependencies (`depends_on` UUID array). Tasks with unmet dependencies start in a `Blocked` state and are automatically unblocked — inside the same DB transaction, with pessimistic locking to prevent duplicate dispatch — as soon as all their dependencies reach `Validated` or `Skipped` status. Cyclic dependency detection (DFS) is enforced at creation time.
- **LightRAG-Style Memory Graph Retrieval** — `memory_search` MCP tool now accepts a `search_mode` parameter: `semantic` (flat keyword search, default), `local` (1-hop entity graph traversal), `global` (high-centrality entity traversal), `hybrid` (semantic + local), and `mix` (semantic + global). Graph traversal uses recursive CTEs on `kg_edges`.
- **MiniRAG Heterogeneous Knowledge Graph** — `kg_edges` table extended with `source_node_type`, `target_node_type` (entity/chunk), and `edge_type` (relates_to/contains/co_occurs/similar) columns. Source provenance tracing (chunk→entity) now supported.
- **Two New KG MCP Tools** — `kg_graph_search` (multi-hop entity traversal with configurable mode and hops) and `kg_edge_provenance` (trace which memory chunks sourced a given entity).
- **AnyTool-Style Progressive Tool RAG** — `ResolveAgentToolsAction` now pre-filters tools through a 3-stage pipeline before the expensive pgvector lookup: (1) keyword token match, (2) fuzzy name similarity (`similar_text` > 60%), (3) semantic pgvector fallback. Reduces LLM context pollution for agents with large tool sets.
- **Lazy MCP Stdio Handle Registry** — `McpHandleRegistry` singleton manages lazily-initialised MCP stdio process handles, preventing redundant subprocess spawns across tool resolutions in the same request.
- **FastCode-Style Code Intelligence Foundation** — New `code_elements` and `code_edges` tables with pgvector HNSW index for semantic code search. `PhpCodeParser` extracts classes, methods, and functions via `nikic/php-parser`. `IndexRepositoryAction` indexes repositories through the existing `GitClientInterface` (no local filesystem clone required). `IndexRepositoryJob` dispatched on repository sync.
- **Code Intelligence Services** — `CodeRetriever` (hybrid pgvector + tsvector search with configurable weights), `CodeGraphTraversal` (N-hop recursive CTE traversal, edge-type filtered in both anchor and recursive parts), `CodeSkimmingService` (signatures-only view, no full content load).
- **Four New Code Intelligence MCP Tools** — `code_search` (hybrid semantic + keyword search over code elements), `code_structure` (file structure outline — classes/methods/functions with line numbers), `code_call_chain` (N-hop call/import/inheritance graph traversal), `code_skim_file` (compact signatures-only file survey).

### Changed

- `CrewTaskStatus` enum extended with `Blocked` case. `blocked` tasks are excluded from active/terminal counts and displayed in orange in the UI.
- `TaskDependencyResolver` updated to use UUID-based `depends_on` references (previously sort_order integers).
- `KgEdge` model extended with `node_type` and `edge_type` scopes for heterogeneous graph queries.

### Fixed

- GitRepository MCP tools now resolve `team_id` explicitly from MCP context (`app('mcp.team_id')`) instead of deriving it from the repository model — prevents cross-tenant access if `TeamScope` is not active in queue context.
- `MemorySearchTool` replaced `auth()->user()?->current_team_id` with `app('mcp.team_id')` — fixes null team_id in stdio MCP connections which previously caused the `team_id` WHERE clause to be silently dropped.
- `DependencyGraph::autoUnblock` adds `lockForUpdate()` on per-dependent re-fetch to prevent duplicate `ExecuteCrewTaskJob` dispatch in concurrent task completion scenarios.
- `CodeGraphTraversal` CTE now applies `edge_type` filter in the recursive part (was anchor-only, causing hops > 1 to traverse edges of wrong type).
- `DecomposeGoalAction` asserts coordinator and worker agent `team_id` matches execution `team_id` after `withoutGlobalScopes()` lookup.
- `CodeRetriever` validates DB-sourced UUIDs with a regex before interpolating into raw SQL `orderByRaw` expression.

## [1.11.0] - 2026-03-25

### Added

- **6 New Workflow Node Executors** — New inline node types for building rich workflows without custom agents:
  - *HttpRequestNode*: outbound HTTP calls (GET/POST/PUT/PATCH/DELETE) with credential template interpolation, SSRF guard, and configurable timeout/redirect handling.
  - *LlmNode*: direct LLM inference inside a workflow step — model, system/user prompt, output variable, and schema validation all configurable per node.
  - *KnowledgeRetrievalNode*: semantic search against team memory with configurable top-k and score threshold; results injected as context into subsequent nodes.
  - *ParameterExtractorNode*: extracts structured parameters from unstructured text via LLM with JSON Schema validation.
  - *VariableAggregatorNode*: collects outputs from all completed predecessor steps and merges them via `array`, `concat`, or `json_merge` strategy. Output capped at 1 MB to prevent memory exhaustion.
  - *TemplateTransformNode*: Mustache-style template rendering using step outputs as context.
- **Langfuse LLMOps Tracing** — New `LangfuseExportMiddleware` in the AI gateway pipeline exports every LLM call as a Langfuse generation trace (fire-and-forget, zero latency impact). Configure via `LANGFUSE_PUBLIC_KEY`, `LANGFUSE_SECRET_KEY`, `LANGFUSE_HOST`. Set `LANGFUSE_MASK_CONTENT=true` to redact prompts before export.
- **Assistant Message Annotations** — Users can rate assistant replies with thumbs-up/down, submit corrections, and add notes. `POST /api/v1/assistant/conversations/{id}/messages/{mid}/annotate`. `AssistantAnnotateMessageTool` for MCP access.
- **Bridge HTTP Tunnel Mode** — Connect a local bridge agent directly via an HTTP endpoint URL instead of the relay WebSocket. Useful when the relay is unreachable. UI in Team Settings with connect/disconnect/ping controls.
- **Bridge-First AI Gateway Default** — New `bridge-first` provider resolution strategy: routes AI calls to a local bridge agent first, falls back to cloud providers when no bridge is available.
- **Integration OAuth2 Migration** — 18 integration drivers migrated from static API-key credentials to OAuth2 token flows with automatic refresh.
- **LinkedIn Integration** — Full OAuth2 integration driver: publish text/link posts, comment on posts, list managed organisations. Uses `/v2/userinfo` OpenID endpoint.
- **Twitter/X Integration** — OAuth 1.0a integration driver: post tweets, reply, like/unlike, retweet, search recent tweets, and get user profiles.
- **WhatsApp Outbound Connector** — New `WhatsAppConnector` registered in `OutboundConnectorManager` for outbound message delivery.
- **Integration Re-Auth Notifications** — Team owner is notified by email and in-app when a connected integration's OAuth token expires and requires re-authorization.
- **Browser Relay Built-In Tool** — New `browser_relay` kind in `BuiltInToolKind` enum; routes browser automation through the bridge relay for remote agent use.
- **SLANG-Inspired Workflow Improvements**:
  - *Workflow Budget Cap*: per-workflow credit cap enforced at runtime; experiments are auto-paused when the cap is hit.
  - *Schema Editor*: visual JSON Schema editor embedded in workflow node config panel.
  - *Graph Overlay*: execution status overlaid on the workflow DAG builder in real time.
  - *Crew Convergence*: configurable quality gate with `max_iterations` and `min_score` thresholds for iterative crew tasks.
- **BroodMind-Inspired Features**:
  - *Send Grace Window*: 3-second cancel window after submitting an assistant message, allowing users to abort before the LLM call fires.
  - *Crew Worker Permission Templates*: per-`CrewMember` permission sets (read/write/admin) evaluated at task execution time.
  - *Agent Heartbeat Scheduling*: agents emit structured heartbeat events on a configurable interval during long-running executions.
- **Memory Tier System** — Five memory tiers (`proposed`, `canonical`, `facts`, `decisions`, `failures`) with promotion/demotion logic and per-tier search filtering.
- **Memory Category Classification** — Memories are auto-classified into categories (instruction, context, fact, decision, lesson) at write time.
- **Per-Agent Memory Capacity Cap** — Pruning command respects a configurable per-agent `memory_capacity` limit, evicting lowest-ranked memories first.
- **Failure Lesson Extraction** — `ExtractFailureLessonsAction` analyses failed experiments and writes structured `failure` memories to prevent repeat mistakes.
- **Agent Context Health Monitoring** — `ContextHealthMonitor` tracks token budget consumption and emits warnings when nearing limits during the tool loop.
- **Semantic Tool-Call Repetition Detection** — Agent tool loop detects semantically duplicate calls (via embedding cosine similarity) and skips or aborts repeated tool invocations.
- **Tool Loop Circuit Breakers** — Configurable `max_consecutive_errors` and `max_total_calls` limits abort runaway tool loops before they exhaust budget.
- **Network Egress Policy for Tools** — Per-tool `network_policy` JSONB column; `EgressPolicyEnforcer` blocks tool calls that would contact disallowed host/port patterns.
- **OCSF Audit Schema** — Audit entries now include OCSF-compatible `category_uid`, `class_uid`, `severity_id`, and `activity_id` fields for SIEM integration.
- **Data Classification Routing** — Agents and skills declare a `data_classification` level (`public`/`internal`/`confidential`/`restricted`); the AI gateway rejects routing classified data to providers below the required tier.
- **Agent Process Isolation Sandbox** *(Enterprise)* — Run agent tool executions inside an isolated subprocess with configurable CPU/memory limits, syscall allowlists, and ephemeral filesystems.
- **Credential Env-Var Injection** — Credential values are injected as `CRED_{NAME}_{KEY}` environment variables into bash tool processes and bridge payloads, so agent scripts can reference secrets without hardcoding.
- **Reddit Browser Session Auto-Refresh** — `RedditSessionRefresher` automatically refreshes browser session tokens for Reddit integration before expiry.
- **Domain Import Boundary PHPStan Rule** — Custom PHPStan rule enforces that domain classes do not import from other domains directly; cross-domain access must go through contracts or actions.
- **311 MCP Tools** — Tool count expanded from 268 to 311 across 38 domains.

### Fixed

- **Passport `scope` Middleware Alias** — Registered `scope` → `CheckToken` and `scopes` → `CheckTokenForAnyScope` aliases in `bootstrap/app.php`; Passport v13 no longer auto-registers these, causing `BindingResolutionException` on every unauthenticated MCP request.
- **Assistant Message IDOR** — `AssistantMessage` had no team scope; route model binding could resolve cross-team messages. Added explicit team ownership check (`abort_if`) in `AssistantController::annotate()`.
- **HTTP Request Node Timeout Cap** — `HttpRequestNodeExecutor` timeout is now capped at 120 s (was unbounded) to prevent long-held outbound connections during workflow execution.
- **Workflow JSON Import Sanitisation** — Imported workflow JSON nodes/edges now validate `type` and team-owned entity UUIDs before materialising, blocking crafted payloads.
- **Crew Quality Gate Convergence** — Quality gate was checking QA score before synthesis completed; reordered to allow synthesis to proceed before scoring.
- **Bridge Relay Response Streaming** — Skips SSE progress keepalive chunks (`:keepalive`) that were causing JSON parse errors in the relay response handler.
- **Bridge Stale Endpoint URL** — Clears `endpoint_url` on the `BridgeConnection` when the relay re-registers a WebSocket connection, preventing stale tunnel references.
- **AI Gateway Google Fallback** — Falls back to `google/gemini-2.5-flash` when no Anthropic or OpenAI keys are configured, preventing silent failures on fresh installs.
- **Memory Agent ID Nullable** — `agent_id` on `memories` is now nullable; `MemoryAddTool` no longer requires an agent context.
- **Reverb Connection Stability** — Increased `ping_interval` to 120 s, `activity_timeout` to 300 s, and tuned Cloudflare-compatible settings to prevent idle connection drops.
- **PWA Service Worker** — Guarded `view transitions` against Livewire 4.5+ `navigate` events; forced SW activation via `skipWaiting`; added play icon for Runs sidebar.
- **LinkedIn API Scope** — Removed unapproved scopes; uses only `w_member_social` to unblock OAuth connection flow.
- **Twitter OAuth** — Switched credential validation to use OAuth 1.0a; bearer token (App-Only) is forbidden on `/users/me`.
- **Project Lead Agent Propagation** — `ExecuteProjectRunJob` now propagates `lead_agent_id` from the project to the newly created experiment.
- **Various Bug Fixes** — `agent_id` added to experiments fillable array, missing `system.deploy` notification label, Blade compile errors in email/approvals docs, `havingRaw` → `whereRaw` for pgvector compatibility.

### Security

- **Langfuse HTTPS Enforcement** — `ExportToLangfuseJob` rejects any `LANGFUSE_HOST` that does not use `https`, preventing credential or prompt data leakage over plain HTTP.
- **Langfuse Prompt Masking** — `LANGFUSE_MASK_CONTENT=true` replaces `systemPrompt`/`userPrompt` with `[REDACTED]` before export.
- **Security Hardening Round 5** — MCP tenant isolation hardened; API token scoping tightened; login throttle applied; webhook replay protection added; SMTP host validation added; header allowlists enforced.
- **OAuth2 Security Hardening** — 6 OAuth2 flow hardening fixes applied across integration drivers (state parameter validation, PKCE enforcement, redirect URI pinning).
- **CVE Patches** — `phpseclib/phpseclib` 3.0.49 → 3.0.50 (CVE-2026-32935 padding oracle); `league/commonmark` 2.8.1 → 2.8.2 (CVE-2026-33347 embed bypass).

## [1.10.0] - 2026-03-21

### Added

- **OpenAI-Compatible Chat Completions API** — Drop-in replacement endpoints at `/v1/chat/completions` and `/v1/models`. Any OpenAI SDK client can now use FleetQ agents as backends with streaming support, usage reporting, and automatic agent-to-model mapping.
- **Marketplace Bundles** — Publish and install multi-entity bundles (agent + skills + workflow + credentials) as a single marketplace listing. Dependency resolution wires up cross-entity references (workflow nodes → agents/skills, agent → skill attachments) automatically on install. Platform-curated bundle seeder included.
- **Context Compaction Middleware** — New AI gateway middleware that automatically compacts long conversation histories to stay within token budgets, preserving key context while reducing token usage.
- **Token Estimator Service** — Predict token counts and costs before executing AI calls, enabling better budget management and cost visibility.
- **Agentic Memory Lifecycle** — Intelligent memory management with:
  - *Write gate*: duplicate detection, contradiction resolution, and confidence scoring before storing new memories.
  - *Memory consolidation*: scheduled command + job that merges related memories to reduce redundancy.
  - *Unified search*: combines semantic similarity, keyword matching, and recency signals in a single query.
  - *Visibility levels*: Private, Project, and Team scopes with auto-promotion based on access patterns.
  - *Tenant hardening*: defense-in-depth guards on retrieval count increment and auto-promotion.
- **Haystack-Inspired Enhancements** — Six features adapted from Haystack pipeline patterns:
  - *Semantic Tool Pre-Filtering*: pgvector embeddings on tools with `tools:embed` command; threshold-based pre-filter reduces tool count before LLM selection.
  - *Workflow YAML/JSON Export/Import*: v2 envelope format with checksum verification, fuzzy reference resolution, 500-node cap. Available via CLI, REST API, and MCP tools.
  - *Workflow Node Type Validation*: port schemas with union type compatibility checks, passthrough resolution, and advisory warnings.
  - *Structured Evaluation Framework*: LLM-as-Judge with XML-based prompt templates, score validation (0–10), datasets, configurable criteria, and MCP tools.
  - *Workflow-as-Tool*: `SynchronousWorkflowExecutor` enables in-process graph execution; agents can invoke workflows as tools via `callable_workflow_ids`.
  - *Enhanced MCP Tool Discovery*: `server_capabilities` column, content-hash caching, and `ToolHealthCheckCommand` for monitoring external MCP server availability.
- **CrewAI-Inspired Phase 1** — Three features adapted from CrewAI patterns:
  - *Conditional Crew Tasks*: `skip_condition` JSONB evaluated via `ConditionEvaluator` before task dispatch; tasks can be dynamically skipped based on prior results.
  - *result_as_answer Tool Flag*: `ResultAsAnswerException` short-circuits the PrismPHP tool loop so tool output becomes the agent's final answer without LLM summarization.
  - *Composite Memory Scoring*: weighted formula combining semantic similarity (0.5), recency decay (0.3), and importance (0.2) with configurable half-life.
- **Agent-as-Tool Delegation** — Agents can call other agents as PrismPHP tools with depth guard, cycle detection, cross-tenant validation, and internal key stripping. Enables hierarchical multi-agent orchestration.
- **Workflow Activation Groups** — Per-node `activation_mode` (all/any/n_of_m) for DAG fan-in control. Backward-compatible merge-to-any semantics.
- **WebMCP Integration (W3C Draft)** — Three-phase browser AI agent tool discovery:
  - *Declarative annotations*: `toolname`/`tooldescription`/`toolparamdescription` attributes on 28 admin pages.
  - *Imperative tools*: `webmcp.js` module with `FleetQWebMcp` global API and 7 imperative tools.
  - *Agent consumption*: `WebMcpBrowserBridge` service for discovering and executing WebMCP tools via CDP.
- **Multi-Bridge Routing** — Priority-based routing with failover chains across multiple bridge connections. Routing preferences UI in Team Settings, `BridgeRouter` integration in `LocalBridgeGateway`. 7 MCP tools for bridge management.
- **Checkpoint Durability Modes** — Three persistence strategies (sync/async/exit) with `CheckpointMode` enum, Redis buffer, and `FlushCheckpointJob`. Configurable per workflow via UI, API, and MCP.
- **Time-Travel Debugging** — `WorkflowSnapshot` model captures execution state at each workflow event. `WorkflowTimeline` Livewire component with split-view snapshot inspector. API endpoint `GET /experiments/{id}/snapshots` and MCP tool `workflow_snapshot_list`.
- **Agent Handoff Pattern** — `ProcessAgentHandoffAction` validates target agent, enforces depth limits, and tracks handoff chains in checkpoint data. System prompt injection for handoff-enabled agents.
- **Dynamic MCP Tool Preferences** — Per-team tool filtering via `shouldRegister()`, `McpToolCatalogTool` for browsing available tools by domain, `McpToolPreferencesTool` for toggling tools on/off, and `listChanged` notifications.
- **CompactMcpServer** — 33 meta-tools optimized for Claude.ai's tool limit, aggregating frequently-used operations into domain-level compound tools.
- **MCP Spec 2025-11-25 Compliance** — `allowed_origins` for DNS rebinding protection, `client_id_metadata_document_supported` in OAuth metadata, strict 401 responses.
- **268 MCP Tools** — Total tool count expanded from 244 to 268 across 37 domains.

### Fixed

- **Workflow Builder Zoom** — Replaced CSS `transform: scale()` with SVG `viewBox` so background grid and edge links render correctly at all zoom levels.
- **`ExperimentTrack` Enum** — Added missing `workflow` case used by `ExecuteChatbotWorkflowJob`.
- **pgvector Migration Guard** — `tool_embeddings` vector column now guarded with `pg_extension` check, preventing migration failure when pgvector is not installed.
- **MCP Pagination Limit** — Increased from 200 to 300 tools to accommodate growing tool catalog.
- **Chatbot Chunks UI** — View Chunks button now visible; embedding provider made configurable per chatbot.
- **CrewAI Security Hardening** — Recursive skip chain converted to iterative loop (50-iteration safety); `havingRaw` → `whereRaw` for pgvector; budget bypass fix; max recursion depth on `ConditionEvaluator`; tenant guards on `last_accessed_at` updates.

---

## [1.9.0] - 2026-03-20

### Added

- **Supabase Integration** — Five-layer native Supabase integration for agent workflows:
  - *Integration driver* (`SupabaseIntegrationDriver`): connect any Supabase project; ping and validate credentials; query tables via PostgREST, execute SQL via RPC, invoke Edge Functions, upload Storage objects. Receives Database Webhook CDC events (INSERT/UPDATE/DELETE) as FleetQ Signals using plain `X-Webhook-Secret` header authentication.
  - *Signal connector* (`SupabaseWebhookConnector`): ingests Supabase Database Webhooks as tagged Signals with CDC metadata (table, schema, event type, record, old_record).
  - *Outbound connector* (`SupabaseRealtimeConnector`): broadcasts agent output to Supabase Realtime channels via the REST broadcast endpoint — no WebSocket required from PHP; idempotent delivery via `xxh128` key.
  - *pgvector memory* (`SupabaseVectorAdapter`): stores and retrieves agent memories using cosine similarity search against the `fleetq_memories` table. `supabase_provision_vector_memory` MCP tool generates the setup SQL for any embedding dimension.
  - *Edge Function skills* (`SkillType::SupabaseEdgeFunction`): invoke any Supabase Edge Function as a reusable platform skill; zero platform credits consumed.
  - Three new MCP tools: `supabase_connector_manage`, `supabase_provision_vector_memory`, `supabase_edge_function_skill`.
- **`SupabaseEdgeFunction` Skill Type** — `ExecuteSupabaseEdgeFunctionSkillAction` handles auth via a linked `Credential` (service role key), configurable HTTP method, timeout, and full error recording via `SkillExecution`.

### Fixed

- **Supabase seeder bug** — `PopularToolsSeeder` was setting `SUPABASE_URL`/`SUPABASE_SERVICE_ROLE_KEY` env vars on the MCP server tool definition; the correct env var for `@supabase/mcp-server-supabase` is `SUPABASE_ACCESS_TOKEN`. Also expanded seeded tool definitions from 3 to 12 (database, edge functions, debugging, development, and docs groups) and increased timeout from 30 s to 60 s.

---

## [1.8.0] - 2026-03-19

### Added

- **MCP OAuth2 Server** — The MCP HTTP endpoint (`/mcp`) is now protected by a full OAuth 2.0 Authorization Code + PKCE flow via Laravel Passport. Supports Dynamic Client Registration (RFC 7591), Authorization Server Metadata (RFC 8414), and Protected Resource Metadata (RFC 9728). Enables Claude.ai, Cursor, and any standards-compliant MCP client to connect with secure user authentication.
- **MCP HTTP Client + Remote MCP Probe** — New `ToolType::McpHttp` transport: connect to any remote MCP server via HTTP/SSE. The `tool_probe_remote_mcp` MCP tool auto-discovers all available tools on a remote server and optionally imports them as platform tools. SSH fingerprint trust management included.
- **244 MCP Tools — Agent-Native Parity** — +17 new tools and 4 updated since v1.7.0, bringing the total to 244. New tools cover granular workflow/project/agent control: individual node execution, graph re-wiring, project run details, per-agent tool sync, remote MCP discovery, and more. Every action a user can perform from the UI is now also available as an MCP tool.
- **CORS Support for Claude.ai / ChatGPT** — New `config/cors.php` enables cross-origin requests for `/mcp`, `/oauth/*`, `/.well-known/*`, and `/api/*` paths. Required for browser-based MCP clients (Claude.ai web UI, ChatGPT).
- **OpenAPI 1.0.0 + OAuth2 Security Scheme** — API documentation at `/docs/api` updated to version 1.0.0. An `oauth2` (authorizationCode) security scheme is now included alongside the existing Bearer token scheme, enabling ChatGPT Actions and other OAuth-capable clients to discover auth endpoints from the spec.

### Fixed

- **OAuth Discovery Chain** — `/.well-known/oauth-protected-resource/mcp` was returning `authorization_servers: ["https://…/mcp"]` (the protected resource URL) instead of `["https://…"]` (the issuer). This broke Claude.ai's OAuth discovery flow. Now replaced with inline route registrations per RFC 9728/8414 that always return the correct issuer URL.
- **`issuer` in oauth-authorization-server** — The issuer field is now always `url('/')` regardless of the path parameter, preventing incorrect issuer values when the endpoint is accessed with a path suffix.
- **Sanctum/Passport Coexistence** — Multiple fixes to allow Sanctum API token auth (`/api/v1/`) and Passport OAuth2 auth (`/mcp`) to work side-by-side on the same User model: `ScopedPersonalAccessToken` model with correct table name, custom `withAccessToken()` override, dropped conflicting string type hints on `can()`/`cant()`, and a `CompatibleSanctumGuard` that accepts both Sanctum and Passport tokens.
- **MCP HTTP Client Stability** — `Connection: close` header to prevent SSH tunnel stalls; explicit connect timeout; SSE response parsing for Streamable HTTP transport; correct MCP `initialize` handshake before `tools/list`/`tools/call`.
- **DecryptException on APP_KEY Rotation** — `updateToken()` now catches `DecryptException` and treats stale tokens as expired instead of crashing.
- **Session Redis DB** — Added a dedicated Redis connection for sessions (DB 3) so session data is isolated from queues (DB 0), cache (DB 1), and locks (DB 2).

### Security

- **MCP Stdio Hardening** — 7 CVEs resolved: command injection in bash tool (`escapeshellarg`), path traversal in filesystem tool, SSRF in HTTP-based MCP transport, token scope bypass, missing rate limits on stdio, unrestricted file read via symlinks, and unauthenticated reflection of server capabilities.
- **OAuth Key Permissions** — Passport OAuth keys are now generated with correct file permissions (0600) on container start via `entrypoint.sh`.

---

## [1.7.0] - 2026-03-16

### Added

- **Social Login** — One-click sign-in and sign-up via Google, GitHub, LinkedIn, X, and Apple using OAuth2 (Socialite + PKCE). Users can link or unlink providers from their profile. Social-only accounts can set a password later. All OAuth flows include state validation and PKCE for public clients.
- **User Profile Settings Page** — New `/profile` route with five tabs: Profile (name/email), Password (change or set initial password for social accounts), Security (2FA enable/disable, QR code, recovery codes), Connected Accounts (link/unlink OAuth providers with lockout guard), and Notifications (per-channel preferences).
- **Header User Dropdown** — Avatar and name in the top-right corner open a dropdown with quick links to Profile Settings, Team Settings, and Sign Out. Replaces the bare logout button.
- **LiteLLM Provider Expansion** — Additional LLM providers are now accessible via the LiteLLM gateway, including any OpenAI-compatible endpoint registered in team settings.
- **Agent Feedback Loop** — Thumbs-up / thumbs-down ratings with optional labels and correction text on agent executions. When three or more negative ratings accumulate within 30 days, `AnalyzeAgentFeedbackAction` automatically generates an Evolution Proposal to improve the agent. Available via UI, agent-to-agent, and MCP tools (`agent_feedback_submit`, `agent_feedback_list`, `agent_feedback_stats`).
- **Git Repository Integration** — Multi-mode git repository linking: read-only file access, commit and PR creation, and inbound webhook signal triggers. Teams can connect any GitHub repository and subscribe to push, PR, and issue events.
- **Bridge Relay Mode** — Run local AI agents (Claude Code, Codex, Kiro, Gemini CLI) as first-class platform workers via the FleetQ Bridge WebSocket relay. Per-agent model selection, live connection status in the Agents settings tab, and auto-discovery of running local agents.
- **Browser-Use Sidecar Client** — Built-in browser automation via the browser-use Cloud sidecar. Agents with the Browser built-in tool can now open URLs, click, fill forms, and extract content without configuring a separate Playwright server.
- **Bash Sandbox Sidecar** — Just-Bash sidecar client with virtual filesystem sandbox support. Agents execute bash scripts in an isolated environment with controlled file access.
- **Chatbot Management Enhancements** — Eight improvements: knowledge base chunk management, analytics summary API, per-session token limits, learning entry archival, bulk KB import, custom system prompt per chatbot, multi-language detection, and webhook session handoff.
- **Plugin Extension Architecture** — Five-phase plugin system for extending platform behaviour without forking. Plugins can register Livewire components, Blade views, routes, event listeners, and MCP tools. Teams enable or disable individual plugins independently via per-team plugin state.
- **PWA Push Notification Prompt** — The notification bell now triggers a push subscription request when push is available, allowing users to opt in to browser push notifications directly from the header.
- **REST API and MCP Expansion** — ~50 new endpoints and 20 new MCP tools covering: Integration lifecycle (connect/disconnect/ping/execute/capabilities), Assistant conversations, Trigger CRUD + dry-run, Memory CRUD + semantic search, Evolution proposal review, Email themes and templates (including AI generation), Agent config history and rollback, Agent runtime state, Delegation depth guard, and Approval SLA escalation.
- **Agent Config Versioning** — All agent configuration changes are stored as versioned snapshots. Teams can browse history, diff versions, and rollback to any previous config via the UI, REST API, or MCP tool `agent_rollback`.
- **Twin-inspired UX** — NLP schedule input ("every Monday at 9am"), run counter badge on project cards, and split model tier view (fast / balanced / powerful) in agent and skill creation forms.
- **Mobile Responsiveness** — Comprehensive responsive layout improvements across all list pages, detail pages, forms, and the workflow builder. Secondary columns collapse on small screens; sidebar opens as a full-height drawer with backdrop.
- **Initial Credit Balance on Install** — `php artisan app:install` seeds a starter credit balance so fresh installations can run experiments immediately.
- **Relay Mode Settings UI** — The Agents settings tab shows live bridge connection status, connected agent list with version info, and copy-ready setup instructions.

### Fixed

- **2FA QR Code** — The `TwoFactorAuthenticatable` trait was missing from the User model, causing `twoFactorQrCodeSvg()` to be undefined and the QR code to never render.
- **Password Error Display** — Named Livewire error bags (`$errors->updatePassword->first()`) are not supported in Livewire 4; switched to the default bag so validation errors appear correctly.
- **500 on `/notifications/preferences`** — `NotificationPreferencesPage` had an outdated `typeLabels` array missing five keys (`experiment.budget.warning`, `approval.escalated`, `human_task.sla_breached`, `budget.exceeded`, `crew.execution.completed`) that `availableChannels()` returns.
- **500 on `/two-factor-challenge`** — `Fortify::twoFactorChallengeView()` was never registered, leaving `TwoFactorChallengeViewResponse` unbound in the container. Added the registration and created the challenge view.
- **Social Login Security** — Four vulnerabilities fixed: PKCE enabled for Google, LinkedIn, and Apple; unverified-email account takeover prevented in the social collect-email flow; provider allowlist validation added to `ConnectedAccountsForm::unlink()`; push subscription endpoint validated (HTTPS-only, 2048-char limit).
- **Bridge Relay Fixes** — Tool-call loop used for Claude Code relay instead of false MCP claim; empty bridge response detected before DB update to prevent Livewire race; local agents routed through bridge relay in relay mode.
- **Bridge Model List** — Bridge agent model list now populated dynamically from active `BridgeConnection` records instead of hardcoded values.
- **Metric and Workflow Generation** — Fixed null `team_id` in `metric_aggregations` inserts and workflow generation requests.
- **Named Parameter Mismatch** — Fixed incorrect named parameter in `RecordAgentConfigRevisionAction` call that caused agent saves to fail silently.
- **MCP Password Guard** — `ProfilePasswordUpdateTool` now explicitly rejects requests that omit `current_password` when the user already has a password set.
- **MCP Profile Update** — Removed `array_filter` from `ProfileUpdateTool` input so empty strings correctly trigger Fortify validation rather than silently retaining old values.

### Security

- PKCE enforced on all public OAuth clients (Google, LinkedIn, Apple).
- Social email collection flow validates provider token before trusting the supplied email address, preventing account takeover via unverified email.
- `ConnectedAccountsForm::unlink()` validates provider against an allowlist before any DB interaction.
- Push subscription endpoint restricted to HTTPS URLs with a 2048-character maximum.

---

## [1.6.0] - 2026-03-11

### Added

- **Multi-Mode Signal Connectors (OAuth + Multi-Subscription)** — Teams can now connect GitHub, Linear, and Jira via OAuth and create multiple independent signal subscriptions per account. Each subscription has its own webhook URL, per-source filter config, and encrypted HMAC secret.
  - *GitHub*: Per-repo webhook registration via GitHub REST API. Supports event type filtering (issues, PRs, push, workflow runs, releases), label filters, and branch filters. Multiple repos per OAuth account.
  - *Linear*: OAuth2 flow (replacing API key); per-team webhook subscriptions via GraphQL `webhookCreate`/`webhookDelete` mutations. Filter by resource type and actions.
  - *Jira*: Atlassian 3LO OAuth with automatic `cloudId` resolution via the accessible-resources API. Dynamic REST webhook registration with 30-day expiry tracking and automatic refresh.
- **ConnectorSignalSubscription Model** — New `connector_signal_subscriptions` table bridges the Integration domain (OAuth accounts) to the Signal ingestion pipeline. Each subscription tracks `webhook_id`, encrypted `webhook_secret`, `webhook_status`, `webhook_expires_at`, `signal_count`, and `last_signal_at`.
- **SubscriptionWebhookController** — New endpoint `POST /api/signals/subscription/{id}` receives inbound payloads from OAuth-registered webhooks. Routes via `IntegrationSignalBridge` → `IngestSignalAction`. Null webhook secrets (Jira) skip HMAC verification; the opaque UUIDv7 in the URL provides security.
- **RefreshExpiringWebhooksJob** — Scheduled weekly job that queries subscriptions expiring within 5 days, deregisters the old webhook at the provider, registers a fresh one, and updates the subscription record. Prevents silent Jira webhook expiry.
- **ConnectorSubscriptionsPage** — New Livewire page at `/signals/subscriptions` for managing per-integration subscriptions with driver-aware filter forms (repo name + event types for GitHub, team ID for Linear, project key for Jira).
- **`connector_subscription_manage` MCP Tool** — Agents can list, get, create, toggle, and delete connector signal subscriptions programmatically. Returns webhook URL and status in responses.
- **`SubscribableConnectorInterface`** — New integration driver contract with `registerWebhook`, `deregisterWebhook`, `verifySubscriptionSignature`, and `mapPayloadToSignalDTO` methods. Implemented by GitHub, Linear, and Jira drivers.
- **`IntegrationSignalBridge` Service** — Routes inbound payloads to matching active subscriptions, applying per-driver mapping and filter logic before calling `IngestSignalAction`.
- **Jira OAuth (Atlassian 3LO)** — `OAuthConnectAction` now supports `extra_params` per driver config (used for Atlassian's required `audience` and `prompt=consent`). `OAuthCallbackAction` resolves and stores `cloud_id` after token exchange.

### Fixed

- **`WebhookRegistrationDTO::webhookSecret`** — Made nullable (`?string`) to support providers (Jira) that don't issue a signing secret. Existing drivers are unaffected.
- **`RefreshExpiringWebhooksJob` schedule** — Fixed `twiceWeekly()` not existing on `CallbackEvent`; changed to `weekly()` which works correctly for job-based schedules.

---

## [1.5.0] - 2026-03-08

### Added

- **20 New Integration Drivers** -- Extended the Integration domain with production-ready drivers across six categories:
  - *Messaging*: Discord (OAuth2, slash commands), Microsoft Teams (webhook + Graph API), WhatsApp Business (Cloud API), Telegram (bot messages).
  - *Monitoring & Alerting*: Datadog (events, alerts, metrics), Sentry (issues, alerts, DSN capture), PagerDuty (incident management, on-call routing).
  - *CRM*: HubSpot (contacts, deals, companies, OAuth2), Salesforce (contacts, opportunities, SOQL, OAuth2).
  - *Email Marketing*: Mailchimp (lists, campaigns, subscribers, OAuth2), Klaviyo (profiles, flows, events, API key).
  - *Productivity*: Google Workspace (Gmail, Drive, Calendar, Sheets — OAuth2), Jira (projects, issues, comments, OAuth2).
  - *Automation Platforms*: Zapier (webhook triggers + Zap API), Make.com (webhooks + scenario control).
- **LocalLlmDiscovery Service** -- Automatic discovery of locally running Ollama instances; dynamically merges available models into the provider list at runtime; health check endpoint in the platform health page.
- **Custom Endpoint Model Discovery** -- Custom OpenAI-compatible endpoints now support dynamic model discovery; the team settings UI shows the model path input and validates the URL before saving.

### Fixed

- **WebhookEndpoint Secret Encryption** -- `WebhookEndpoint.secret` upgraded from APP_KEY AES-256-CBC to per-team XSalsa20-Poly1305 (`TeamEncryptedString` cast); `credentials:re-encrypt` command extended to migrate existing records.

### Security

- Webhook endpoint secrets now protected by the same per-team envelope encryption as API credentials, isolating them from APP_KEY compromise.

---

## [1.4.0] - 2026-03-07

### Added

- **Password Recovery** -- Forgot-password and reset-password pages powered by Laravel Fortify; "Forgot password?" link on the login page; full email-based reset flow with token validation and expiry.
- **Assistant LLM Configuration** -- Teams can set a dedicated AI provider and model for the assistant chat in Team Settings, independently of the default workflow LLM. Override applies workspace-wide.
- **Media Analysis Toggle** -- Per-team setting to enable or disable automatic vision analysis of image and PDF attachments on incoming signals; uses credits when enabled.
- **Approval Timeout Setting** -- Teams can configure the default number of hours before pending approval requests expire, overriding the platform default.
- **Collapsible Sidebar Groups** -- Navigation reorganised into five collapsible sections — Build, Run, Integrate, Communicate, System — with open/closed state persisted in `localStorage` per user.
- **Email Theme & Template System** -- Full email theme and template management: custom branding variables, AI-powered template generation, live preview, and archival. Full MCP tool coverage and API access for programmatic control; plan-gated per workspace.
- **Responsive List Pages** -- Secondary columns (description, dates, metadata) are hidden on mobile across all entity list pages (agents, skills, crews, tools, credentials, workflows, projects, experiments, signals, and more).

### Fixed

- **Assistant Tool Calling** -- Provider-aware `toolChoice` parameter is now only sent to Anthropic, Google, OpenAI, and OpenRouter; streaming completions no longer lose tool-loop results; OpenRouter `{"text":"..."}` wrapper responses are unwrapped correctly.
- **Mobile Layout** -- Sidebar opens as a full-height drawer on mobile with a backdrop overlay; team-switcher hidden on mobile; navigation list is scrollable on small screens.
- **PWA Service Worker** -- `sw.js`, `offline.html`, and `browserconfig.xml` are now correctly included in cloud deployments and served directly by nginx.
- **Test Isolation** -- `ApiTestCase::tearDown()` flushes the array cache to prevent `RateLimiter` state from bleeding across tests in the same PHP process.

---


## [1.3.0] - 2026-03-04

### Added

- **Customer-Managed Encryption Keys (BYOKMS)** -- Enterprise teams can bring their own KMS (AWS KMS, GCP Cloud KMS, or Azure Key Vault) to wrap the team's Data Encryption Key (DEK). Credentials encrypted with per-team envelope encryption remain accessible only through the customer's KMS — revoking KMS access immediately revokes data access. Three-layer DEK cache (in-memory, Redis, KMS API) minimizes latency and KMS costs. UI in Team Settings Security tab, `kms_manage` MCP tool, and 5 API endpoints (`/api/v1/team/kms/*`).
- **KMS Job Middleware** -- `CheckKmsAvailable` middleware on pipeline stage jobs detects KMS errors before job execution and notifies team admins when KMS becomes unreachable.
- **KMS Plan Downgrade Handling** -- Automatic KMS removal when a team downgrades from Enterprise, ensuring no orphaned KMS configurations.
- **Five New Local CLI Agents** -- Gemini CLI (Google), Kiro CLI (AWS), Aider (open-source), Amp (Sourcegraph), and OpenCode (open-source) join Claude Code and Codex as supported local execution backends. All auto-detected, zero platform cost, with agent-specific command builders and output parsers.
- **Plan-Gated Feature Visibility** -- Locked features now appear with a disabled overlay and upgrade CTA instead of being hidden; `x-plan-gate` Blade component with inline/overlay/ghost modes; `canCreate` ghost buttons on all list pages.
- **49 Platform Tool Integrations** -- Pre-built MCP tool definitions (GitHub, Slack, Notion, Linear, Jira, and more) are now seeded as platform-wide tools visible to all teams, with per-team activation and credential overrides.

### Security

- Per-team credentials now use XSalsa20-Poly1305 envelope encryption (sodium_crypto_secretbox) instead of Laravel's default AES-256-CBC, with per-team DEK isolated from APP_KEY when KMS is active.
- KMS error state blocks all credential operations (no silent fallback to APP_KEY).

---

## [1.2.0] - 2026-03-03

### Added

- **What's New Page** -- Changelog page at `/changelog` rendered from `CHANGELOG.md` with collapsible version cards, category badges (New, Improved, Fixed, Security), and markdown-formatted content.
- **Unread Changelog Badge** -- Blue dot indicator in sidebar next to "What's New" link shows when there are changes since the user's last visit; auto-clears on page view via `changelog_seen_at` timestamp.
- **Contextual Page Help** -- In-app help panel (`<x-page-help />`) with page-specific guidance, tips, and links; accessible via `<x-page-help-button />` on every page.
- **Custom AI Endpoints** -- Teams can configure their own OpenAI-compatible API endpoints (proxy gateways, self-hosted models, third-party providers) via Team Settings; SSRF-validated URLs; per-team `custom_endpoint` PrismPHP driver; zero platform credits; `custom_endpoint_manage` MCP tool.
- **Dynamic Sidebar Version** -- Sidebar footer now reads version from `config('app.version')` instead of hardcoded value.
- **View Sync CI Check** -- `scripts/check-view-sync.php` validates that cloud view overrides contain all required components and route patterns from base views; prevents layout drift.

### Fixed

- Theme-aware help panel colors for dark mode support.
- Cloud sidebar now includes all base navigation routes (marketplace, memory, signals, changelog).
- Cloud layout includes contextual help components (`<x-page-help-button />`, `<x-page-help />`).

### Security

- Cloud edition now explicitly forces `local_llm.enabled = false` (defense in depth).
- `CloudProviderResolver` filters both `local` (CLI agents) and `http_local` (Ollama, openai_compatible) providers from cloud UI.
- Cloud Team Settings LLM dropdown uses filtered providers instead of raw config.

---

## [1.1.1] - 2026-02-28

### Fixed

- Migration `create_platform_tools_support` now guards PostgreSQL-specific `DROP/CREATE POLICY` statements with a driver check, preventing failures when running against SQLite (test environment).
- Telegram webhook test updated to send the required `X-Telegram-Bot-Api-Secret-Token` header, aligning with the webhook secret enforcement added in v1.1.0.
- PHPStan and Pint style issues in platform-tools and security files resolved.

## [1.1.0] - 2026-02-28

### Added

- **Platform Tool Marketplace** -- Curated tools activatable per team from a central catalog; `ActivatePlatformToolAction` / `DeactivatePlatformToolAction`; `tool_activate` / `tool_deactivate` MCP tools; team-level activation state managed via `team_tool_activations` pivot table.
- **HMAC Tracking URL Signer** -- `TrackingUrlSigner` service signs click/pixel URLs; cloud enforces signatures, community edition logs-and-continues for backwards compatibility.

### Security

- **SSRF Protection (outbound)** -- New shared `SsrfGuard` service blocks RFC 1918, loopback, and link-local addresses for both IPv4 and IPv6. Applied to `WebhookOutboundConnector` and `RssConnector`. Enabled globally in cloud via `services.ssrf.validate_host = true`.
- **Webhook header injection** -- `WebhookOutboundConnector` now uses an allowlist (only `x-*` and `content-type` headers forwarded); blocks `Authorization`, `Host`, and `Cookie` injection.
- **Revenue attribution integrity** -- `AttributeRevenueAction::execute()` accepts `?string $owningTeamId`; validates that the `experiment_id` from Stripe metadata belongs to the paying customer's team before recording revenue.
- **MCP cross-tenant reads** -- Explicit `where('team_id', ...)` added to `ExperimentCostTool`, `CrewExecutionStatusTool`, `SemanticCachePurgeTool`, `SemanticCacheStatsTool`, `AuditLogTool`, `MemorySearchTool`, `MemoryListRecentTool`, `SignalGetTool`, and `ArtifactGetTool`.
- **Token abilities** -- `TeamController::createToken()` always forces `['team:{id}']`; `AuthController::token/refresh()` no longer fall back to `['*']` wildcard.
- **Team API authorization** -- `TeamController::removeMember()` and `update()` now require `Gate::authorize('manage-team')`.
- **Cross-tenant agent references** -- `CrewController::store/update()` validate agent IDs against the current team.
- **Password change** -- `updateMe()` revokes all other tokens when password changes; `UpdateMeRequest` requires `current_password` to change password.
- **Tracking metrics** -- `TrackingController` resolves `team_id` from experiment before `Metric::create()`; tracking pixel returns 404 on invalid signature.
- **Email header injection** -- `SmtpEmailConnector` strips `\r\n<>` from `fromAddress` before embedding in `List-Unsubscribe` header.
- **Auth rate limiting** -- `/auth/refresh` separately throttled at 10 requests/minute.
- **Signal HMAC** -- `WebhookConnector` uses constant-time comparison for HMAC verification.
- **Datadog webhook** -- `DatadogAlertWebhookController` accepts secret from `X-Datadog-Webhook-Secret` header (path-based token deprecated).

### Changed

- `UsageMeter::isWithinLimit()` documented as a non-atomic UI-only peek; use `incrementIfWithinLimit()` for enforcement gates.
- `TeamScope` / `BelongsToTeam`: clarified `orWhereNull` semantics for platform records.

### Fixed

- `SemanticCache` middleware: strip trailing whitespace from prompt text before embedding.
- `MarketplaceBrowsePage`: MCP status filter and bundle monetization UI guard.
- `NaturalLanguageScheduleParser`: timezone edge case handling.

---

## [Unreleased]

### Added

- **Local LLM Support** -- Run inference against Ollama or any OpenAI-compatible server (LM Studio, vLLM, llama.cpp, Jan) with no cloud API keys required:
  - New `ollama` provider: 17 preset models — Llama 3.3/3.2/3.1, Mistral 7B/Nemo, Qwen 2.5 (7B/14B/72B/Coder), Gemma 3, Phi-4, DeepSeek-R1, Codestral.
  - New `openai_compatible` provider: configure any OpenAI-compatible endpoint (LM Studio, vLLM, text-generation-webui, Ollama's OpenAI shim) with a custom base URL.
  - Zero platform credits charged — inference runs entirely on your hardware.
  - SSRF protection: user-supplied URLs validated and blocked from link-local and reserved IP ranges in production; always allows localhost.
  - `TeamProviderCredential` stores `base_url`, `api_key`, and optional `models` list per provider.
  - Settings UI: "Local LLM Endpoints" section in Team Settings (visible when `LOCAL_LLM_ENABLED=true`).
  - `local_llm_manage` MCP tool: `status`, `configure_ollama`, `configure_openai_compatible`, `discover_models`, `remove` actions.
  - Activation: set `LOCAL_LLM_ENABLED=true` and optionally `LOCAL_LLM_SSRF_PROTECTION=false` for LAN IPs.

- **Pluggable Compute Providers** -- New `gpu_compute` skill type backed by a provider-agnostic infrastructure:
  - Providers: **RunPod** (existing), **Replicate**, **Fal.ai**, **Vast.ai** — each with synchronous and asynchronous execution modes.
  - `ComputeProviderManager`: registry with per-provider credential resolution, health check, and job execution.
  - `ExecuteGpuComputeSkillAction`: replaces the single-provider `runpod_endpoint` action; reads `provider` from skill configuration (defaults to `runpod`).
  - `compute_manage` MCP tool: `provider_list`, `credential_save`, `credential_check`, `credential_remove`, `health_check`, `run` actions across all providers.
  - `config/compute_providers.php`: per-provider API base URLs, timeout, and capability flags.
  - Zero platform credits for all GPU compute executions; actual inference costs billed directly to the provider account.

- **Integration Domain** -- Connect external services to the platform via a unified driver interface:
  - Built-in drivers: **GitHub** (webhooks + polling), **Slack** (OAuth2 + events), **Notion** (OAuth2 + database queries), **Airtable** (API key + polling), **Linear** (API key + webhooks), **Stripe** (webhook events), **Generic Webhook** (HMAC-verified inbound), **API Polling** (configurable interval).
  - `IntegrationDriverInterface`: standardised `connect`, `disconnect`, `ping`, `listTriggers`, `listActions`, `executeAction`, `verifyWebhook` contract.
  - `IntegrationManager`: driver registry with lazy instantiation.
  - `Integration` model: encrypted credentials, status lifecycle (`pending/active/error/disconnected`), last-pinged-at tracking.
  - OAuth 2.0 flow: `OAuthConnectAction` + `OAuthCallbackAction` + `IntegrationOAuthController` (redirect + callback routes).
  - Background health monitoring: `PingIntegrations` command (every 15 min), `PollIntegrations` command (per-driver frequency), `RefreshExpiringIntegrationTokens` command (token refresh 1 h before expiry).
  - Inbound webhook routing via `IntegrationWebhookController` (`POST /api/integrations/{integration}/webhook`).
  - `integration_manage` MCP tool: `list`, `get`, `connect`, `disconnect`, `ping`, `execute_action`, `list_triggers`, `list_actions` actions.
  - Livewire UI: `/integrations` (list) and `/integrations/{integration}` (detail with action panel).

### Added
- **RunPod GPU Cloud Integration** -- Two new skill types for GPU-accelerated workloads:
  - **`runpod_endpoint`** — invoke any RunPod serverless endpoint synchronously or asynchronously; BYOK API key via Team Settings; `input_mapping` support; zero platform credits charged.
  - **`runpod_pod`** — full GPU pod lifecycle (create → wait until RUNNING → HTTP request → stop) within a single skill execution; configurable Docker image, GPU type, spot pricing, environment variables, and startup timeout; cost_credits recorded for analytics.
  - `runpod_manage` MCP tool: 10 actions covering credential management, serverless endpoints, and pod operations (`credential_save/check/remove`, `endpoint_run/status/health`, `pod_create/list/status/stop`).
  - GPU price catalog in `config/runpod.php` with 12 models and spot discount support.
- **Workflow Engine Enhancements** -- 5 new node types and capabilities:
  - **TimeGate Node** — delay-based gate with configurable `delay_seconds`; `PollWorkflowTimeGatesCommand` cron resumes expired gates automatically
  - **Multiple Output Channels** — edge-level `source_channel`/`target_channel` routing so nodes can fan-out to labelled downstream paths
  - **Merge Node** — OR-join semantics; proceeds when the first incoming branch completes, ignoring remaining branches
  - **Event-Chain Tracking** — `WorkflowNodeEvent` model records every node execution with event type, duration, input/output summaries, and parent chain links; Execution Chain tab in ExperimentDetailPage; `workflow_execution_chain` MCP tool
  - **Sub-Workflow Node** — spawn a child experiment from a reusable workflow blueprint; parent waits and resumes when child reaches a terminal state
- **Inbound Signal Connectors UI** -- Manage Slack, HTTP monitor, GitHub events, IMAP OAuth2, and Telegram connectors from a dedicated page
- **Push Notifications** -- Web push via `laravel-notification-channels/webpush`; configurable per-event preferences
- **Event-Driven Trigger Rules** -- Define conditions on incoming signals to auto-start projects; Telegram bot routing
- **Agent Templates** -- 14 pre-built agent templates across 5 categories with gallery page, search, and one-click deploy
- **Agent Evolution** -- AI-driven self-improvement with execution analysis, proposal generation, and one-click apply
- **Agent Personality** -- Configurable personality traits (tone, verbosity, creativity, risk tolerance, collaboration style)
- **Webhook System** -- Outbound webhooks with event filtering, secret signing, retry logic, and management UI
- **Testing Framework** -- Regression test suites for agent outputs with automated evaluation
- **Project Kanban Board** -- Visual kanban and graph views for project experiments
- **MCP Tools** -- 19 additional MCP tools across new domains bringing coverage to 112 tools total; `IsDestructive` / `IsReadOnly` annotations on all tools
- **OpenAPI annotations** -- Scramble `@tags` and `@response` doc-blocks on all 18 API controllers for richer OpenAPI 3.1 output
- **Deployment Mode service** -- `DeploymentMode` service gates cloud-only vs self-hosted-only features; cloud landing page and auth flows respect deployment context

### Changed
- Expanded default skills catalog (14 agents, updated skills and tools)
- Updated REST API to 99 endpoints (from 68)
- Architecture table updated to reflect 16 bounded contexts (from 12)
- README screenshots now use responsive thumbnail grid layout
- SMTP outbound connector now resolves team-configured credentials instead of relying solely on platform `.env` keys
- Email connector is always team-configurable regardless of deployment mode

### Fixed
- `TestCase` bootstrap detection now checks if `Cloud\\` namespace is registered in the current autoloader instead of relying on file-path heuristics — fixes standalone base tests being broken when run inside the cloud repo
- PHPStan baseline regenerated from scratch (1509 suppressed errors; all prior stale entries removed)
- Laravel Pint style fixes across Phases 9 and 10 files

---

## 2026-02-15

### Added
- **AutoForge-inspired enhancements (v2)** -- Multi-terminal experiment panel, project kanban board, expanded execution modes
- **Execution guardrails** -- TTL, depth, and concurrency middleware for experiment jobs
- **Memory domain** -- Knowledge document upload with text extraction

### Changed
- Expanded execution mode options for projects

---

## 2026-02-12

### Added
- **Tool risk classification** -- Tools categorized by risk level (safe, low, medium, high, critical)
- **Project execution modes** -- Autonomous, supervised, and restricted modes with tool filtering
- **Self-healing workflow** -- Automatic retry with alternative strategies on step failure

---

## 2026-02-08

### Added
- **MCP Server** -- 65 Model Context Protocol tools across 15 domains (stdio + HTTP/SSE transports)
- **AI Assistant** -- Context-aware chat panel embedded in every page with 28 built-in tools
- **Universal Artifacts** -- Versioned artifacts for experiments, crews, and project runs
- **Durable Workflow Execution** -- Checkpoints, human task forms with SLA enforcement, switch nodes, dynamic forks, do-while loops
- **Prompt-to-Workflow** -- Generate workflow graphs from natural language descriptions

### Changed
- Workflow DAG expanded to 8 node types (added human_task, switch, dynamic_fork, do_while)

---

## 2026-01-28

### Added
- **Connectors** -- WhatsApp, Discord, Teams, Google Chat outbound connectors
- **Agent Memory** -- pgvector-backed semantic memory with knowledge base
- **Media Understanding** -- Image/document analysis pipeline for agent inputs
- **Memory Browser** -- UI page for browsing and managing agent memories

---

## 2026-01-22

### Added
- **Tool Management** -- MCP server (stdio/HTTP) and built-in tool (bash/filesystem/browser) support with per-agent assignment
- **Credential Vault** -- Encrypted storage for external service credentials with rotation and expiry tracking
- **CI Pipeline** -- GitHub Actions with Laravel Pint, PHPStan level 5, and test suite

### Changed
- Unified tasks panel for workflow and standard experiments
- Seeded 16 popular tools (all disabled by default)

---

## 2026-01-18

### Added
- **Per-agent LLM config** -- Provider and model selection per agent with fallback chains
- **Project dependencies** -- Feature dependency graph for project planning

---

## 2026-01-15

### Added
- **Continuous Projects** -- Long-running agent projects with cron scheduling, budget caps, milestones, and overlap policies
- **Crew API** -- Full CRUD + execution endpoints for agent crews

---

## 2026-01-10

### Added
- **Agent Crews** -- Multi-agent teams with lead/member roles, parallel execution, and result synthesis
- **Local Agent Integration** -- Codex and Claude Code as zero-cost local execution backends
- **Stuck Task Recovery** -- Automatic detection and recovery of stalled experiment tasks

---

## 2026-01-05

### Added
- **REST API v1** -- 59 endpoints with Sanctum auth, cursor pagination, and OpenAPI 3.1 docs at `/docs/api`
- **Agent Workflows** -- Visual DAG builder with graph executor and experiment integration

---

## 2026-01-01

### Added
- **Agent Fleet Community Edition** -- Initial release
- Experiment pipeline with 20-state machine
- AI agents with roles, goals, backstories, and skill assignments
- Reusable skills (LLM, connector, rule, hybrid) with versioning
- Signal ingestion (webhooks, RSS)
- Multi-channel outbound (email, Telegram, Slack)
- Human-in-the-loop approval queue
- Budget controls with credit ledger
- Metrics and revenue attribution
- Full audit trail
- Marketplace for sharing skills, agents, and workflows
- Docker Compose deployment (PHP 8.4, PostgreSQL 17, Redis 7, Horizon)
