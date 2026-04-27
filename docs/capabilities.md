# FleetQ — Platform Capabilities for Coding Agents

**Read this before implementing anything.** Many capabilities already exist — check here first to avoid duplicating features.

---

## Web Search — SearXNG

Self-hosted meta-search engine (Google + Bing + DuckDuckGo + Wikipedia). Zero per-query cost.

**Setup** — add to `.env`:
```
SEARXNG_URL=http://searxng:8080
SEARXNG_SECRET_KEY=<random-secret>
```
Start the `searxng` Docker service (already defined in `docker-compose.yml`).

**MCP tool**: `searxng_search`
- `query` (required)
- `categories` — `general` | `images` | `news` | `science` | `social_media` | `videos`
- `max_results` — 1–20 (default: 5)

**Code**: `app/Domain/Signal/Connectors/SearxngConnector.php`, `app/Mcp/Tools/Signal/SearxngSearchTool.php`

---

## Signal Connectors (Inbound)

Receive events from external sources as `Signal` records. `app/Domain/Signal/Connectors/`.

| Driver | Type | Purpose |
|--------|------|---------|
| `webhook` | Webhook | Generic HTTP webhook receiver |
| `rss` | Poll | RSS/Atom feed polling |
| `imap` | Poll | Email inbox polling via IMAP |
| `api_polling` | Poll | Generic REST API polling |
| `searxng` | Poll | Search results as signals |
| `github` | Webhook | GitHub push/PR events |
| `github_issues` | Poll | GitHub issues & PRs |
| `github_wiki` | Poll | GitHub Wiki page changes |
| `jira` | Poll | Jira issue transitions |
| `linear` | Poll | Linear issue tracking |
| `notion` | Poll | Notion database/page changes |
| `confluence` | Poll | Confluence page changes |
| `sentry` | Poll | Sentry error alerts |
| `datadog` | Poll | Datadog alert monitoring |
| `pagerduty` | Poll | PagerDuty incident monitoring |
| `http_monitor` | Poll | HTTP endpoint uptime/status |
| `calendar` | Poll | Google Calendar events |
| `telegram` | Poll | Telegram bot messages |
| `slack` | Webhook | Slack message events |
| `discord` | Webhook | Discord message events |
| `whatsapp` | Webhook | WhatsApp messages |
| `matrix` | Webhook | Matrix protocol messages |
| `signal_protocol` | Webhook | Signal encrypted messages |
| `supabase_webhook` | Webhook | Supabase Realtime events |
| `screenpipe` | Poll | Local screen & audio capture (OCR + transcription) |
| `clearcue` | Webhook | ClearCue intent scoring signals |
| `manual` | Manual | User-triggered (no config needed) |

Polling triggered by scheduler: `php artisan connectors:poll [--driver=rss]` (every 15 min).

**MCP tools**: `signal_ingest`, `connector_binding`, `inbound_connector_manage`

---

## Outbound Connectors (Delivery)

Send messages to external platforms. `app/Domain/Outbound/Connectors/`.

| Channel | Required env vars | Purpose |
|---------|------------------|---------|
| `email` | `MAIL_*` | Via platform mail driver |
| `email` (smtp) | per-connector config | Custom SMTP |
| `telegram` | `TELEGRAM_BOT_TOKEN` | Telegram bot |
| `slack` | `SLACK_BOT_USER_OAUTH_TOKEN` or `SLACK_WEBHOOK_URL` | Slack channel |
| `discord` | `DISCORD_BOT_TOKEN` | Discord channel |
| `webhook` | — | HTTP POST to any URL |
| `ntfy` | — | Push via ntfy.sh |
| `teams` | `TEAMS_WEBHOOK_URL` | Microsoft Teams |
| `google_chat` | `GOOGLE_CHAT_WEBHOOK_URL` | Google Chat |
| `whatsapp` | `WHATSAPP_PHONE_NUMBER_ID`, `WHATSAPP_ACCESS_TOKEN` | WhatsApp |
| `matrix` | — | Matrix room |
| `signal_protocol` | — | Signal encrypted |
| `supabase_realtime` | — | Supabase Realtime broadcast |
| `notification` | — | In-app only |
| `dummy` | — | No-op (testing) |

**MCP tools**: `connector_config_list`, `connector_config_save`, `connector_config_test`

---

## AI Agents

Configured with role/goal/backstory. Skills and tools can be attached per agent.

**Env vars** (at least one required):
```
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
GOOGLE_AI_API_KEY=
LOCAL_AGENTS_ENABLED=true   # Claude Code, Codex — auto-detected, zero cost
```

**MCP tools**: `agent_list`, `agent_get`, `agent_create`, `agent_update`, `agent_toggle_status`, `agent_delete`, `agent_skill_sync`, `agent_tool_sync`

---

## Skills

Reusable AI skill definitions. Types: `llm` / `connector` / `rule` / `hybrid` / `guardrail`.

**MCP tools**: `skill_list`, `skill_get`, `skill_create`, `skill_update`, `skill_versions`

---

## Tools (MCP Servers & Built-in)

Attach external MCP servers or built-in capabilities to agents.

- **`mcp_stdio`** — local MCP server process
- **`mcp_http`** — remote MCP server over HTTP
- **`built_in`** — `bash` / `filesystem` / `browser`
- **`compute_endpoint`** — GPU compute endpoint (RunPod, etc.)

**MCP tools**: `tool_list`, `tool_create`, `tool_discover_mcp`, `tool_import_mcp`

### GPU Tool Templates

Pre-configured GPU tool templates with 1-click deploy. 16 templates: GLM-OCR, Whisper, SDXL Turbo, FLUX.1, BGE-M3, XTTS v2, Qwen2.5 Coder, Mistral 7B, Wan2.1, Florence-2, SAM 2, Table Transformer, Kokoro TTS, NLLB-200, MusicGen, F5-TTS.

**UI**: `/tools/templates` | **API**: `GET /api/v1/tool-templates`, `POST /api/v1/tool-templates/{id}/deploy`
**MCP tools**: `tool_template_manage`

### MCP Marketplace

Browse and install MCP servers from the Smithery registry (300+ servers).

**UI**: `/tools/marketplace` | **Code**: `McpRegistryClient`, `McpMarketplacePage`

---

## Credentials

Encrypted storage for API keys, OAuth2 tokens, bearer tokens. Auto-injected into agent executions.

**MCP tools**: `credential_list`, `credential_create`, `credential_rotate`, `credential_oauth_initiate`

---

## Workflows (Visual DAG)

Multi-step pipelines with branching, loops, and human approval gates.

**Node types**: `start`, `end`, `agent`, `conditional`, `human_task`, `switch`, `dynamic_fork`, `do_while`

**MCP tools**: `workflow_list`, `workflow_create`, `workflow_save_graph`, `workflow_validate`, `workflow_generate` (AI from natural language), `workflow_estimate_cost`, `workflow_import`, `workflow_export`

---

## Projects

Scheduled or on-demand execution wrappers with budget caps.

**MCP tools**: `project_list`, `project_create`, `project_activate`, `project_trigger_run`

---

## Experiments

Core pipeline with 20-state machine (Draft → Scoring → Planning → Building → Executing → Completed).

**MCP tools**: `experiment_list`, `experiment_create`, `experiment_start`, `experiment_retry`, `experiment_kill`, `experiment_steps`

---

## Memory

Semantic memory store backed by pgvector (1536d HNSW cosine similarity).

**MCP tools**: `memory_search`, `memory_list_recent`, `memory_stats`, `memory_delete`

---

## Knowledge Graph

Entity-relationship facts with vector embeddings for contextual retrieval.

**MCP tools**: `kg_search`, `kg_entity_facts`, `kg_add_fact`

---

## Approvals / Human Tasks

Human-in-the-loop gates. Embedded in workflow nodes or triggered independently.

**MCP tools**: `approval_list`, `approval_approve`, `approval_reject`, `approval_complete_human_task`

---

## Real-World Action Governance

Generalized proposal flow that gates assistant tool calls, integration writes, and git operations behind a per-tier risk policy. Approvals auto-execute. Visible alongside outbound approvals in the unified `/approvals` inbox.

**Domain model**: `ActionProposal` (polymorphic via `target_type` discriminator: `tool_call`, `integration_action`, `git_push`).

**Per-tier policy** in `team.settings.action_proposal_policy = {low, medium, high}`, each `'auto' | 'ask' | 'reject'`. Tier→risk mapping: read=low, write=medium, destructive=high. Legacy `slow_mode_enabled=true` is preserved as `{high: 'ask'}`.

**Gates**:
- `IntegrationActionGate` — single chokepoint inside `ExecuteIntegrationActionAction::execute()` covers all 50+ integration drivers. Heuristic verb classifier maps action names to tiers.
- `GitOperationGate` — `GitOperationRouter::resolve()` wraps the `GitClientInterface` in a `GatedGitClient` decorator. All 27 MCP `GitRepository/*` tools inherit transparently.
- Assistant slow-mode gate — `wrapToolsWithSlowModeGate()` applied in `SendAssistantMessageAction` after `getTools($user)` resolution.

**Bypass via container binding**: `app('integration_gate.bypass')` and `app('git_gate.bypass')` short-circuit gates during approved-proposal re-execution. Wrap in try/finally.

**Auto-execute on approval**: `ApproveActionProposalAction` → `ActionProposalApproved` event → `DispatchActionProposalExecution` listener → `ExecuteActionProposalJob` (queued, idempotent) → `ActionProposalExecutor::execute($proposal, $actor)` dispatches by `target_type`.

**Conversation result append**: when `payload.conversation_id` is set, `AppendExecutionResultToConversation` listener writes the outcome back to the originating assistant conversation as an assistant-role message.

**MCP tools**: `action_proposal_list`, `action_proposal_get`, `action_proposal_approve`, `action_proposal_reject`.

---

## Public Discovery (`/.well-known/fleetq`)

Public, unauthenticated capability manifest. External AI tools (Cursor, Codex, Claude Code, OpenCode) can hit one URL to learn the MCP HTTP/stdio endpoints, REST API base, OpenAPI URL, auth scheme, and tool count. Each block is gated by a `discovery.expose_*` config flag (env-driven) so operators can scrub the public surface.

**Endpoint**: `GET /.well-known/fleetq` — public, throttled to 60 req/min per IP, no auth, no CSRF.

**Config**: `config/discovery.php` — flags `expose_name`, `expose_version`, `expose_mcp`, `expose_api`, `expose_auth`, `expose_tool_count`, `expose_generated_at` (all default `true`).

**MCP parity**: `system_discovery_get` MCP tool returns the same payload from inside an MCP session.

**Source**: `App\Http\Controllers\WellKnownFleetQController`.

---

## Live Team Graph

Cytoscape.js force-directed visualization at `/team-graph`. Updates in real-time via Laravel Reverb WebSockets when an agent runs or an experiment transitions. Shape semantics: agents=rect, humans=ellipse-with-initials, crews=hexagon.

**Real-time event**: `TeamActivityBroadcast` (broadcast on private team channel). Emitted from `BroadcastAgentExecuted` and `BroadcastExperimentTransitioned` listeners.

**Reverb infrastructure**: `reverb` Docker service. `laravel-echo` + `pusher-js` declared in both parent and base `package.json`. Server-side `REVERB_HOST=reverb`, browser-side `VITE_REVERB_HOST=localhost` (or your public hostname). Echo with `wire:poll.5s` fallback when sockets are unavailable.

**MCP tool**: `team_graph_get` returns the same graph data programmatically.

---

## Crews (Multi-agent)

Coordinated execution of multiple agents on a shared goal.

**MCP tools**: `crew_list`, `crew_create`, `crew_execute`, `crew_execution_status`

---

## Integrations

55+ integrations with external platforms across 14 categories. OAuth2, API key, and webhook-based auth.

Key integrations: GitHub, Slack, Notion, Linear, Jira, HubSpot, Stripe, Apify, 1Password, Screenpipe, and more.

**MCP tools**: `integration_list`, `integration_connect`, `integration_disconnect`, `integration_ping`, `integration_execute`

---

## Triggers

Event-driven rules that automatically launch experiments when signal conditions are met.

**MCP tools**: `trigger_rule_list`, `trigger_rule_create`, `trigger_rule_test`

---

## Git Repositories

Connect git repos for code-aware agent execution.

**MCP tools**: `git_repo_list`, `git_commit`, `git_diff`, `git_branch_list`, `git_pr_create`

---

## Marketplace

Publish and install skills, agents, and workflows.

**MCP tools**: `marketplace_browse`, `marketplace_install`, `marketplace_publish`

---

## Budget & Cost Tracking

1 credit = $0.001 USD. All LLM calls tracked. Reservations use 1.5× multiplier.

**MCP tools**: `budget_summary`, `budget_check`, `budget_forecast`

---

## Chatbots

Deploy conversational chatbot instances with custom knowledge bases.

**MCP tools**: `chatbot_list`, `chatbot_create`, `chatbot_toggle_status`

---

## Voice Sessions (LiveKit)

Real-time voice agent sessions.

**Env vars**: `LIVEKIT_URL`, `LIVEKIT_API_KEY`, `LIVEKIT_API_SECRET`

**MCP tools**: `voice_session_list`, `voice_session_create`

---

## AI Assistant (Platform Chat)

Context-aware chat embedded in the platform. 28 role-gated tools for querying and mutating platform state via natural language.

**MCP tools**: `assistant_send_message`, `assistant_conversation_list`

---

## Optional Docker Services

| Profile | Service | Purpose | Env vars |
|---------|---------|---------|---------|
| *(default)* | `searxng` | Web search | `SEARXNG_URL`, `SEARXNG_SECRET_KEY` |
| `relay` | `relay` | Bridge relay for remote agents | `RELAY_PORT`, `FLEETQ_API_URL` |
| `browser` | `browserless` | Headless Chrome for browser skills | `BROWSERLESS_TOKEN` |
| `sandbox` | `bash_sidecar` | Sandboxed code execution | `BASH_SIDECAR_SECRET` |

---

## Key Artisan Commands

```bash
php artisan app:install              # First-time setup wizard
php artisan connectors:poll          # Poll all active signal connectors
php artisan connectors:poll --driver=rss   # Poll specific driver only
php artisan agents:health-check      # Check all active agents
php artisan fleet:doctor [--fix]     # System health diagnostics
php artisan memories:consolidate     # Merge similar agent memories
php artisan skills:reindex           # Regenerate pgvector embeddings for skills
php artisan tools:discover           # Auto-discover local MCP servers
php artisan workflow:import {file}   # Import workflow from JSON/YAML
php artisan workflow:export {id}     # Export workflow to JSON/YAML
php artisan system:check-updates     # Check for newer FleetQ version
```

---

## MCP Server

All features above are accessible programmatically via the MCP server:

```bash
php artisan mcp:start agent-fleet   # stdio (for Claude Code, Codex)
# or POST /mcp with Sanctum bearer token (for Cursor, remote clients)
```

345+ tools across 33+ domains.
