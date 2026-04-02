# Changelog

All notable changes to Agent Fleet Community Edition are documented here.

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
