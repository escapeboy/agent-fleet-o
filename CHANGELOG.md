# Changelog

All notable changes to Agent Fleet Community Edition are documented here.

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
