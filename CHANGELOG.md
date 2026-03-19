# Changelog

All notable changes to Agent Fleet Community Edition are documented here.

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
