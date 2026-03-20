# FleetQ - Community Edition

## Stack

- **Framework:** Laravel 12 (PHP 8.4)
- **Database:** PostgreSQL 17 (with `tpetry/laravel-postgresql-enhanced`)
- **Cache/Queue/Sessions:** Redis 7 (predis client, DB 0 queues / DB 1 cache / DB 2 locks)
- **Frontend:** Livewire 4 + Tailwind CSS 4 + Alpine.js
- **Build:** Vite 7
- **AI Gateway:** PrismPHP (`prism-php/prism` ^0.99)
- **Queue Manager:** Laravel Horizon
- **Auth:** Laravel Fortify (with 2FA support), Laravel Sanctum (API tokens)
- **Audit:** spatie/laravel-activitylog
- **API Docs:** dedoc/scramble (OpenAPI 3.1 at `/docs/api`)
- **API Filtering:** spatie/laravel-query-builder
- **MCP Server:** `laravel/mcp` ^0.5 (Model Context Protocol for LLM/agent access)
- **Docker:** PHP 8.4-fpm-alpine + Nginx 1.27 + PostgreSQL 17 + Redis 7

## Git Branching Strategy

- **Default branch:** `develop` — all day-to-day work happens here.
- **Production branch:** `main` — stable releases only, synced from `develop`.
- **Feature branches:** Create from `develop`, open PRs into `develop`.
  - Naming: `feat/<short-description>`, `fix/<short-description>`, `chore/<short-description>`.
- **Hotfix branches:** Create from `main`, open PRs into `main`. Then sync `main` back into `develop`.

## Project Structure

Domain-driven design with 16 bounded contexts:

```
app/
  Domain/                        # Business logic by domain
    Agent/                       # AI agent management & execution
      Actions/                   # CreateAgent, ExecuteAgent, GenerateAgentName, DisableAgent, HealthCheck
      Enums/                     # AgentStatus
      Models/                    # Agent (SoftDeletes, role/goal/backstory, skills), AiRun, AgentExecution
    Crew/                        # Multi-agent teams
      Actions/                   # CreateCrew, UpdateCrew, ExecuteCrew, DecomposeGoal, SynthesizeResult, ValidateTaskOutput, CollectCrewArtifacts
      Enums/                     # CrewStatus, CrewMemberRole, CrewProcessType, CrewExecutionStatus, CrewTaskStatus
      Jobs/                      # ExecuteCrewJob
      Models/                    # Crew, CrewMember, CrewExecution, CrewTaskExecution
      Services/                  # CrewOrchestrator
    Experiment/                  # Core experiment pipeline & state machine
      Actions/                   # Create, Transition, Kill, Pause, Resume, Retry, RetryFromStep, CollectWorkflowArtifacts
      Enums/                     # ExperimentStatus (20 states), ExperimentTrack, StageType, StageStatus, ExecutionMode
      Events/                    # ExperimentTransitioned
      Listeners/                 # DispatchNextStageJob, NotifyOnCriticalTransition, RecordTransitionMetrics, CollectWorkflowArtifactsOnCompletion
      Models/                    # Experiment, ExperimentStage, ExperimentStateTransition, PlaybookStep
      Pipeline/                  # BaseStageJob + 7 stage jobs, ExecutePlaybookStepJob, PlaybookExecutor
      Services/                  # ArtifactContentResolver, StepOutputBroadcaster, CheckpointManager
      States/                    # ExperimentStateMachine, ExperimentTransitionMap, TransitionPrerequisiteValidator
    Signal/                      # Inbound signal processing
      Actions/                   # IngestSignalAction
      Connectors/                # WebhookConnector, RssConnector, ManualSignalConnector
      Contracts/                 # InputConnectorInterface
      Models/                    # Signal
    Outbound/                    # Outbound delivery
      Actions/                   # SendOutbound, CheckBlacklist
      Connectors/                # Email, SmtpEmail, Telegram, Slack, Webhook, Dummy
      Contracts/                 # OutboundConnectorInterface
      Enums/                     # OutboundChannel, OutboundProposalStatus, OutboundActionStatus
      Exceptions/                # BlacklistedException, RateLimitExceededException
      Middleware/                # ChannelRateLimit, TargetRateLimit
      Models/                    # OutboundProposal, OutboundAction
    Approval/                    # Human-in-the-loop & human tasks
      Actions/                   # CreateApprovalRequest, Approve, Reject, ExpireStaleApprovals, CreateHumanTask, CompleteHumanTask, EscalateHumanTask
      Enums/                     # ApprovalStatus
      Models/                    # ApprovalRequest (also serves as HumanTask when linked to workflow node)
    Budget/                      # Cost tracking & enforcement
      Actions/                   # ReserveBudget, SettleBudget, CheckBudget, AlertOnLowBudget
      Enums/                     # LedgerType
      Exceptions/                # InsufficientBudgetException
      Listeners/                 # PauseOnBudgetExceeded
      Models/                    # CreditLedger
      Services/                  # CostCalculator
    Metrics/                     # Measurement & attribution
      Actions/                   # AttributeRevenueAction
      Models/                    # Metric, MetricAggregation
    Audit/                       # Full audit trail
      Listeners/                 # LogExperimentTransition, LogApprovalDecision, LogBudgetEvent, LogAgentEvent
      Models/                    # AuditEntry
    Skill/                       # Reusable AI skill definitions
      Actions/                   # CreateSkill, ExecuteSkill, UpdateSkill
      Enums/                     # SkillType (llm/connector/rule/hybrid), SkillStatus, RiskLevel, ExecutionType
      Models/                    # Skill, SkillVersion, SkillExecution
      Services/                  # SchemaValidator, SkillCostCalculator
    Tool/                        # LLM tool management (MCP servers, built-in tools)
      Actions/                   # CreateTool, UpdateTool, DeleteTool, ResolveAgentTools
      Enums/                     # ToolType (mcp_stdio/mcp_http/built_in), ToolStatus (active/disabled), BuiltInToolKind (bash/filesystem/browser)
      Models/                    # Tool (SoftDeletes, encrypted credentials, agent_tool pivot)
      Services/                  # ToolTranslator (converts Tool models to PrismPHP Tool objects)
    Credential/                  # External service credential management
      Actions/                   # CreateCredential, UpdateCredential, DeleteCredential, RotateCredentialSecret, ResolveProjectCredentials
      Enums/                     # CredentialType (api_key/oauth2/basic_auth/bearer_token/custom), CredentialStatus (active/disabled/expired/revoked)
      Models/                    # Credential (encrypted secrets, expiry tracking)
    Workflow/                    # Reusable workflow templates (visual DAG builder)
      Actions/                   # CreateWorkflow, UpdateWorkflow, DeleteWorkflow, ValidateWorkflowGraph, EstimateWorkflowCost, MaterializeWorkflow, GenerateWorkflowFromPrompt
      Enums/                     # WorkflowNodeType (start/end/agent/conditional/human_task/switch/dynamic_fork/do_while), WorkflowStatus (draft/active/archived)
      Models/                    # Workflow, WorkflowNode, WorkflowEdge
      Services/                  # WorkflowGraphExecutor, GraphValidator, ConditionEvaluator
    Project/                     # Continuous & one-shot projects
      Actions/                   # CreateProject, UpdateProject, PauseProject, ResumeProject, ArchiveProject, RestartProject, TriggerProjectRun
      Enums/                     # ProjectStatus, ProjectType (one_shot/continuous), ProjectRunStatus, ScheduleFrequency, OverlapPolicy, MilestoneStatus
      Jobs/                      # DispatchScheduledProjectsJob, ExecuteProjectRunJob, DeliverWorkflowResultsJob
      Listeners/                 # SyncProjectStatusOnRunComplete, LogProjectActivity
      Models/                    # Project, ProjectSchedule, ProjectRun, ProjectMilestone
      Notifications/             # ProjectBudgetWarning, ProjectDigest, ProjectMilestoneReached, ProjectRunFailed, ProjectRunCompleted
      Services/                  # ProjectScheduler
    Assistant/                   # AI-powered platform assistant chat
      Actions/                   # SendAssistantMessageAction (sync tool-loop + streaming)
      Jobs/                      # ProcessAssistantMessageJob
      Models/                    # AssistantConversation (context-bound), AssistantMessage (with tool_calls/tool_results)
      Services/                  # AssistantToolRegistry (28 PrismPHP tools, role-gated), ContextResolver, ConversationManager
      Tools/                     # ListEntitiesTools, GetEntityTools, StatusTools, MemoryTools, MutationTools
    Marketplace/                 # Skill, agent & workflow marketplace
      Actions/                   # PublishToMarketplace, InstallFromMarketplace
      Enums/                     # MarketplaceStatus, ListingVisibility
      Models/                    # MarketplaceListing, MarketplaceInstallation, MarketplaceReview
    Shared/                      # Cross-domain
      Enums/                     # TeamRole
      Models/                    # Team, TeamProviderCredential, UserNotification
      Notifications/             # WeeklyDigest, Welcome
      Scopes/                    # TeamScope
      Services/                  # NotificationService
      Traits/                    # BelongsToTeam
    Trigger/                     # Event-driven trigger rules
      Actions/                   # EvaluateTriggerRulesAction, ExecuteTriggerRuleAction
      Enums/                     # TriggerRuleStatus
      Jobs/                      # EvaluateTriggerRulesJob
      Models/                    # TriggerRule
      Services/                  # TriggerConditionEvaluator, SignalInputMapper
    Telegram/                    # Telegram bot integration (signal connector + assistant chat)
      Actions/                   # RegisterTelegramBotAction, ProcessTelegramMessageAction, SendTelegramReplyAction
      Enums/                     # TelegramRoutingMode
      Jobs/                      # ProcessTelegramMessageJob
      Models/                    # TelegramBot, TelegramChatBinding
  Infrastructure/
    AI/                          # Provider-agnostic LLM gateway
      Contracts/                 # AiGatewayInterface, AiMiddlewareInterface
      DTOs/                      # AiRequestDTO, AiResponseDTO, AiUsageDTO
      Gateways/                  # PrismAiGateway (BYOK credentials), FallbackAiGateway, LocalAgentGateway
      Middleware/                # RateLimiting, BudgetEnforcement, IdempotencyCheck, SchemaValidation, UsageTracking
      Models/                    # LlmRequestLog, CircuitBreakerState
      Services/                  # CircuitBreaker, ProviderResolver, LocalAgentDiscovery
  Mcp/                           # MCP Server (Model Context Protocol)
    Concerns/                    # BootstrapsMcpAuth (stdio auth bootstrap)
    Servers/                     # AgentFleetServer (200+ tools across 31 domains)
    Tools/                       # MCP tool implementations
      Agent/                     # agent_list, agent_get, agent_create, agent_update, agent_toggle_status, agent_delete, agent_config_history, agent_rollback, agent_runtime_state, agent_skill_sync, agent_tool_sync, agent_templates_list
      Experiment/                # experiment_list, experiment_get, experiment_create, experiment_start, experiment_pause, experiment_resume, experiment_retry, experiment_kill, experiment_valid_transitions, experiment_retry_from_step, experiment_steps, experiment_cost, experiment_share
      Crew/                      # crew_list, crew_get, crew_create, crew_update, crew_execute, crew_execution_status, crew_executions_list
      Skill/                     # skill_list, skill_get, skill_create, skill_update, skill_versions, guardrail, multi_model_consensus, code_execution, browser_skill
      Tool/                      # tool_list, tool_get, tool_create, tool_update, tool_delete, tool_activate, tool_deactivate, tool_discover_mcp, tool_import_mcp, tool_ssh_fingerprints, tool_bash_policy
      Credential/                # credential_list, credential_get, credential_create, credential_update, credential_rotate, credential_oauth_initiate, credential_oauth_finalize
      Workflow/                  # workflow_list/get/create/update/validate/generate/activate/duplicate/save_graph/estimate_cost/suggestion/time_gate/execution_chain
      Project/                   # project_list, project_get, project_create, project_update, project_activate, project_pause, project_resume, project_trigger_run, project_archive
      Approval/                  # approval_list, approval_approve, approval_reject, approval_complete_human_task, approval_webhook_config
      Artifact/                  # artifact_list, artifact_get, artifact_content, artifact_download_info
      Signal/                    # signal_list, signal_ingest, signal_get, connector_binding, contact_manage, imap_mailbox, email_reply, http_monitor, alert_connector, slack_connector, ticket_connector, clearcue_connector, intent_score, kg_search, kg_entity_facts, kg_add_fact, connector_subscription, inbound_connector_manage, connector_binding_delete
      Budget/                    # budget_summary, budget_check, budget_forecast
      Marketplace/               # marketplace_browse, marketplace_publish, marketplace_install, marketplace_review, marketplace_categories, marketplace_analytics
      Memory/                    # memory_search, memory_list_recent, memory_stats, memory_delete, memory_upload_knowledge
      System/                    # system_dashboard_kpis, system_health, system_version_check, system_audit_log, global_settings_update
      Webhook/                   # webhook_list, webhook_create, webhook_update, webhook_delete
      Shared/                    # notification_manage, team_get, team_update, team_members, local_llm, team_byok_credential_manage, custom_endpoint_manage, api_token_manage
      Evolution/                 # evolution_proposal_list, evolution_analyze, evolution_apply, evolution_reject
      Cache/                     # semantic_cache_stats, semantic_cache_purge
      Telegram/                  # telegram_bot_manage
      Trigger/                   # trigger_rule_list, trigger_rule_create, trigger_rule_update, trigger_rule_delete, trigger_rule_test
      Outbound/                  # connector_config_list/get/save/delete/test
      Integration/               # integration_list, integration_connect, integration_disconnect, integration_ping, integration_execute, integration_capabilities
      Assistant/                 # assistant_conversation_list, assistant_conversation_get, assistant_send_message, assistant_conversation_clear
      Email/                     # email_theme_list/get/create/update/delete, email_template_list/get/create/update/delete/generate
      Chatbot/                   # chatbot_list/get/create/update/toggle_status/session_list/analytics_summary/learning_entries
      Bridge/                    # bridge_status, bridge_endpoint_list, bridge_endpoint_toggle, bridge_disconnect
      Compute/                   # compute_manage
      RunPod/                    # runpod_manage
  Http/Controllers/              # SignalWebhookController, TrackingController, ArtifactPreviewController
  Http/Controllers/Api/V1/      # 30 REST API controllers (~175 endpoints)
  Http/Middleware/               # SetCurrentTeam
  Livewire/                      # Admin panel components
    Dashboard/                   # DashboardPage
    Experiments/                 # List, Detail, Create, Timeline, TasksPanel, ExecutionLog, Transitions, Outbound, Metrics, Artifacts, WorkflowProgress
    Approvals/                   # ApprovalInboxPage, HumanTaskForm
    Audit/                       # AuditLogPage
    Settings/                    # GlobalSettingsPage
    Health/                      # HealthPage
    Skills/                      # List, Detail, Create
    Agents/                      # List, Detail, Create
    Tools/                       # List, Detail, Create
    Credentials/                 # List, Detail, Create
    Crews/                       # List, Detail, Create, ExecutionPage, ExecutionPanel
    Workflows/                   # List, Builder (visual DAG editor), Detail, ScheduleWorkflowForm
    Projects/                    # List, Detail, Create, Edit, ActivityTimeline, RunsTable
    Marketplace/                 # Browse, Detail, Publish
    Assistant/                   # AssistantPanel (embedded in layout, no dedicated route)
    Teams/                       # TeamSettingsPage (BYOK + API tokens)
    Triggers/                    # TriggerRulesPage, CreateTriggerRuleForm
    Shared/                      # NotificationBell (header), NotificationInboxPage
  Console/Commands/              # AgentHealthCheck, AggregateMetrics, ExpireStaleApprovals, PollInputConnectors,
                                 # SendWeeklyDigest, CleanupAuditEntries, CheckProjectBudgets, RecoverStuckTasks,
                                 # CheckHumanTaskSla, InstallCommand
  Jobs/Middleware/               # CheckKillSwitch, CheckBudgetAvailable, TenantRateLimit
```

## Routes

### Web Routes (auth-protected, Livewire)

| Path | Component | Name |
|------|-----------|------|
| `GET /` | Redirect to dashboard/login | home |
| `GET /dashboard` | DashboardPage | dashboard |
| `GET /experiments` | ExperimentListPage | experiments.index |
| `GET /experiments/{experiment}` | ExperimentDetailPage | experiments.show |
| `GET /skills` | SkillListPage | skills.index |
| `GET /skills/create` | CreateSkillForm | skills.create |
| `GET /skills/{skill}` | SkillDetailPage | skills.show |
| `GET /agents` | AgentListPage | agents.index |
| `GET /agents/create` | CreateAgentForm | agents.create |
| `GET /agents/{agent}` | AgentDetailPage | agents.show |
| `GET /tools` | ToolListPage | tools.index |
| `GET /tools/create` | CreateToolForm | tools.create |
| `GET /tools/{tool}` | ToolDetailPage | tools.show |
| `GET /credentials` | CredentialListPage | credentials.index |
| `GET /credentials/create` | CreateCredentialForm | credentials.create |
| `GET /credentials/{credential}` | CredentialDetailPage | credentials.show |
| `GET /crews` | CrewListPage | crews.index |
| `GET /crews/create` | CreateCrewForm | crews.create |
| `GET /crews/{crew}` | CrewDetailPage | crews.show |
| `GET /crews/{crew}/execute` | CrewExecutionPage | crews.execute |
| `GET /projects` | ProjectListPage | projects.index |
| `GET /projects/create` | CreateProjectForm | projects.create |
| `GET /projects/{project}/edit` | EditProjectForm | projects.edit |
| `GET /projects/{project}` | ProjectDetailPage | projects.show |
| `GET /workflows` | WorkflowListPage | workflows.index |
| `GET /workflows/create` | WorkflowBuilderPage | workflows.create |
| `GET /workflows/{workflow}/schedule` | ScheduleWorkflowForm | workflows.schedule |
| `GET /workflows/{workflow}/edit` | WorkflowBuilderPage | workflows.edit |
| `GET /workflows/{workflow}` | WorkflowDetailPage | workflows.show |
| `GET /marketplace` | MarketplaceBrowsePage | marketplace.index |
| `GET /marketplace/publish` | PublishForm | marketplace.publish |
| `GET /marketplace/{listing:slug}` | MarketplaceDetailPage | marketplace.show |
| `GET /artifacts/{artifact}/render/{version?}` | ArtifactPreviewController | artifacts.render |
| `GET /approvals` | ApprovalInboxPage | approvals.index |
| `GET /health` | HealthPage | health |
| `GET /audit` | AuditLogPage | audit |
| `GET /settings` | GlobalSettingsPage | settings |
| `GET /team` | TeamSettingsPage | team.settings |
| `GET /triggers` | TriggerRulesPage | triggers.index |
| `GET /triggers/create` | CreateTriggerRuleForm | triggers.create |
| `GET /notifications` | NotificationInboxPage | notifications.index |

### API v1 Routes (`/api/v1/`)

~175 endpoints across 30 controllers, Sanctum bearer token auth, cursor pagination, OpenAPI 3.1 docs at `/docs/api`.

| Group | Endpoints | Purpose |
|-------|-----------|---------|
| Auth | `POST token`, `POST refresh`, `DELETE token`, `GET devices`, `DELETE devices/{id}` | Token management |
| Me | `GET /me`, `PUT /me` | Current user |
| Experiments | `GET`, `GET {id}`, `POST`, `POST {id}/transition`, `POST {id}/pause`, `POST {id}/resume`, `POST {id}/retry`, `POST {id}/kill`, `POST {id}/retry-from-step`, `GET {id}/steps` | Experiment CRUD + transitions + actions |
| Projects | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/activate`, `POST {id}/pause`, `POST {id}/resume`, `POST {id}/restart`, `POST {id}/trigger`, `GET {id}/runs` | Project CRUD + lifecycle + runs |
| Agents | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `PATCH {id}/status`, `GET {id}/config-history`, `POST {id}/rollback`, `GET {id}/runtime-state` | Agent CRUD + toggle + config history |
| Skills | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `GET {id}/versions` | Skill CRUD + versions |
| Tools | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `GET ssh-fingerprints` | Tool CRUD |
| Credentials | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/rotate` | Credential CRUD + secret rotation |
| Crews | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/execute`, `GET {id}/executions`, `GET {id}/executions/{eid}` | Crew CRUD + execution |
| Workflows | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `PUT {id}/graph`, `POST {id}/validate`, `POST {id}/activate`, `POST {id}/duplicate`, `GET {id}/cost` | Workflow CRUD + graph ops |
| Signals | `GET`, `GET {id}`, `POST` | Signal CRUD |
| Approvals | `GET`, `GET {id}`, `POST {id}/approve`, `POST {id}/reject`, `POST {id}/complete-human-task`, `POST {id}/escalate` | Approval + human task management |
| Triggers | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `PATCH {id}/status`, `POST {id}/test` | Trigger CRUD + toggle + dry-run |
| Memory | `GET`, `GET {id}`, `POST`, `DELETE {id}`, `POST /search`, `GET /stats` | Memory CRUD + semantic search |
| Evolution | `GET`, `GET {id}`, `POST {id}/apply`, `POST {id}/reject` | Evolution proposal review |
| Email Templates | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/generate` | Email template CRUD + AI generate |
| Email Themes | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}` | Email theme CRUD |
| Integrations | `GET`, `GET {id}`, `POST /connect`, `POST {id}/disconnect`, `POST {id}/ping`, `POST {id}/execute`, `GET {id}/capabilities` | Integration lifecycle |
| Assistant | `GET conversations`, `POST conversations`, `GET conversations/{id}`, `DELETE conversations/{id}`, `POST conversations/{id}/messages` | AI assistant chat |
| Marketplace | `GET`, `GET {slug}`, `POST`, `POST {slug}/install`, `POST {slug}/reviews` | Marketplace browse + publish |
| Team | `GET`, `PUT`, `GET members`, `DELETE members/{id}`, `GET credentials`, `POST credentials`, `DELETE credentials/{id}`, `GET tokens`, `POST tokens`, `DELETE tokens/{id}` | Team management + BYOK |
| Dashboard | `GET /dashboard` | KPI summary |
| Health | `GET /health` | System health |
| Audit | `GET /audit` | Audit log |
| Artifacts | `GET`, `GET {id}`, `GET {id}/content`, `GET {id}/download` | Artifact CRUD + content + download |
| Budget | `GET /budget` | Budget summary |
| Outbound Connectors | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/test` | Outbound connector CRUD + test |
| Webhook Endpoints | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}` | Outbound webhook CRUD |
| Chatbot Instances | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/tokens`, `GET {id}/conversations` | Chatbot management |
| Bridge | `GET /status`, `POST /register`, `POST /endpoints`, `POST /heartbeat`, `DELETE /` | Bridge agent relay |

### MCP Routes (`routes/ai.php`)

| Transport | Path/Name | Server | Auth | Purpose |
|-----------|-----------|--------|------|---------|
| HTTP/SSE | `/mcp` | AgentFleetServer | Sanctum bearer token | Remote MCP clients (Cursor, etc.) |
| stdio | `agent-fleet` | AgentFleetServer | Auto (default team owner) | Local CLI agents (Codex, Claude Code) |

200+ MCP tools across 31 domains. Start local server: `php artisan mcp:start agent-fleet`

### Legacy API Routes (`/api/`)

| Method | Path | Controller | Auth | Purpose |
|--------|------|------------|------|---------|
| `POST` | `/api/signals/webhook` | SignalWebhookController | HMAC | Signal ingestion |
| `POST` | `/api/telegram/webhook/{teamId}` | TelegramWebhookController | Secret token | Telegram bot updates |
| `GET` | `/api/track/click` | TrackingController | -- | Click tracking (302 redirect) |
| `GET` | `/api/track/pixel` | TrackingController | -- | Open tracking (1x1 pixel) |

## Single-Team Architecture

The community edition uses an "implicit single team" pattern:
- A default team is created during `php artisan app:install`
- All domain models use `BelongsToTeam` trait with `TeamScope` global scope
- `SetCurrentTeam` middleware resolves the team from session
- All registered users are attached to the default team
- No team switching, no invitations, no multi-tenancy UI
- BYOK (Bring Your Own Key): API keys configured via Settings page or `.env`

## MCP Server

The platform exposes a Model Context Protocol (MCP) server via `laravel/mcp`, giving LLMs and agents full programmatic access.

### Architecture
- **Server:** `app/Mcp/Servers/AgentFleetServer.php` — registers 121 tools across 23 domains
- **Auth (stdio):** `BootstrapsMcpAuth` trait auto-resolves default team owner, sets `mcp.active` flag
- **Auth (HTTP):** Sanctum bearer token via `auth:sanctum` middleware
- **TeamScope:** Console mode bypasses `TeamScope` unless `mcp.active` is bound in the container
- **Routes:** `routes/ai.php` — `Mcp::web('/mcp')` + `Mcp::local('agent-fleet')`

### Tool Pattern
Each tool extends `Laravel\Mcp\Server\Tool`:
```php
#[IsReadOnly]  // or #[IsDestructive]
#[IsIdempotent]
class AgentListTool extends Tool
{
    protected string $name = 'agent_list';
    protected string $description = '...';
    public function schema(JsonSchema $schema): array { ... }
    public function handle(Request $request): Response { ... }
}
```

### Usage
```bash
# Local stdio (for Codex, Claude Code)
php artisan mcp:start agent-fleet

# HTTP/SSE (for Cursor, remote clients)
# Endpoint: POST /mcp with Sanctum bearer token
```

### IMPORTANT: When adding/modifying features
When any domain functionality is added or changed, the corresponding MCP tool(s) must also be created or updated in `app/Mcp/Tools/`. The MCP server must maintain 100% coverage of platform capabilities. Also update `AgentFleetServer.php` tool registration if adding new tools.

## Conventions

### Code Style
- **Models:** Use `HasUuids` trait (UUIDv7). No auto-increment IDs.
- **Enums:** PHP 8.1 backed enums in `Enums/` folder per domain.
- **Actions:** Single-responsibility action classes. One public `execute()` method.
- **DTOs:** Immutable data transfer objects with `readonly` properties.
- **No repositories:** Use Eloquent directly in actions.

### Form Components (Blade)
Reusable form components in `resources/views/components/`:
- `x-form-input` -- text/number/email/password with label, error, hint
- `x-form-select` -- dropdown with label, error
- `x-form-textarea` -- multi-line with label, error, hint, mono mode
- `x-form-checkbox` -- checkbox with label
- `x-form-radio` -- radio button with label

### Database
- All primary keys are UUID (UUIDv7).
- JSONB columns with GIN indexes (PostgreSQL).
- Partial indexes for frequently filtered statuses.
- Budget operations use `lockForUpdate()` for pessimistic locking.
- 74 migrations.

### State Machine
- Custom implementation (NOT spatie/laravel-model-states).
- `ExperimentTransitionMap::canTransition()` validates all transitions.
- `TransitionPrerequisiteValidator` checks preconditions before transitions.
- `transitionTo()` uses `SELECT FOR UPDATE` within a DB transaction.
- Side effects in event listeners, not in the transition itself.

### Pipeline
- Event-driven advancement (NOT job chains).
- `ExperimentTransitioned` event triggers 8 listeners:
  1. `DispatchNextStageJob` -- advances to next pipeline stage (supports playbooks)
  2. `RecordTransitionMetrics` -- records timing/metrics
  3. `NotifyOnCriticalTransition` -- alerts on failures
  4. `PauseOnBudgetExceeded` -- auto-pause on low budget
  5. `LogExperimentTransition` -- audit trail
  6. `SyncProjectStatusOnRunComplete` -- syncs ProjectRun status when experiment finishes
  7. `LogProjectActivity` -- logs project-level activity
  8. `CollectWorkflowArtifactsOnCompletion` -- collects artifacts from completed workflow steps
- All stage jobs extend `BaseStageJob`.
- Job middleware: `CheckKillSwitch`, `CheckBudgetAvailable`, `TenantRateLimit`.
- Workflow experiments use `PlaybookExecutor` with `WorkflowGraphExecutor` for DAG traversal.
- `RetryFromStepAction` supports graph-aware BFS reset (target + downstream steps only).
- Checkpoint system: PlaybookSteps store `checkpoint_data`, `worker_id`, `idempotency_key` for resumable long-running steps.

### Workflow DAG
- 8 node types: `start`, `end`, `agent`, `conditional`, `human_task`, `switch`, `dynamic_fork`, `do_while`.
- `HumanTask` nodes create `ApprovalRequest` with `form_schema`, wait for human completion via `CompleteHumanTaskAction`.
- `Switch` nodes evaluate expressions and route via `case_value` on edges.
- `GenerateWorkflowFromPromptAction` uses Claude Sonnet 4 to decompose natural language into workflow graphs.
- SLA enforcement: `CheckHumanTaskSla` command escalates/expires overdue human tasks every 5 minutes.

### Artifact System
- Universal artifacts: `Artifact` model links to `Experiment`, `CrewExecution`, or `ProjectRun` (all nullable FKs).
- Versioned: `ArtifactVersion` stores content with version number, created_by `AiRun`.
- `ArtifactContentResolver` resolves category (code/document/data/media), file extension, MIME type.
- `CollectCrewArtifactsAction` and `CollectWorkflowArtifactsOnCompletion` listener auto-collect artifacts.

### Assistant
- Context-aware AI chat embedded in layout (no dedicated route).
- 28 PrismPHP tools role-gated: read (all users), write (Member+), destructive (Admin/Owner).
- Supports cloud providers (via PrismPHP), local agents (Claude Code text-based tool loop, Codex MCP).
- `ConversationManager` uses sliding window (max 30 messages, ~50k tokens).
- Context binding: assistant auto-receives context from the current page (experiment, project, agent, crew, workflow).

### Tool System
- Three tool types: `mcp_stdio` (local MCP servers), `mcp_http` (remote MCP), `built_in` (bash/filesystem/browser).
- Tools are team-scoped, linked to agents via `agent_tool` pivot table with priority and overrides.
- `ToolTranslator` converts Tool models to PrismPHP Tool objects at inference time.
- `ResolveAgentToolsAction` resolves tools at execution time, filtering by project `allowed_tool_ids`.
- 16 popular tools seeded by `PopularToolsSeeder` (all disabled by default).
- Tools requiring API keys have empty placeholders in transport_config.

### Credential System
- Encrypted storage for external service credentials (API keys, OAuth2, bearer tokens, etc.).
- `ResolveProjectCredentialsAction` injects credentials into agent executions.
- Secret rotation via `RotateCredentialSecretAction`.
- Expiry tracking with automatic status transitions.

### Queue Architecture
- 6 queues: `critical`, `ai-calls`, `experiments`, `outbound`, `metrics`, `default`
- Redis: DB 0 (queues), DB 1 (cache), DB 2 (locks)

### AI Gateway
- Provider-agnostic via PrismPHP.
- Middleware pipeline: RateLimiting, BudgetEnforcement, IdempotencyCheck, SchemaValidation, UsageTracking.
- Circuit breaker per provider.
- Fallback chains: anthropic -> openai, openai -> anthropic, google -> anthropic.
- Supported: Anthropic (Claude), OpenAI (GPT-4o), Google (Gemini).
- Local agents: Codex and Claude Code as execution backends (auto-detected via `LocalAgentDiscovery`).
- `LocalAgentGateway` spawns CLI processes, zero cost, no API key required.
- `ProviderResolver` hierarchy: skill -> agent -> team -> platform (filters local agents to only show detected ones).
- Tool-augmented inference: agents with attached tools get PrismPHP Tool objects injected into LLM calls.

### Scheduled Commands
- `approvals:expire-stale` -- hourly
- `agents:health-check` -- every 5 minutes (currently disabled)
- `metrics:aggregate --period=hourly` -- hourly
- `metrics:aggregate --period=daily` -- daily at 01:00
- `connectors:poll --driver=rss` -- every 15 minutes
- `digest:send-weekly` -- weekly on Monday at 09:00
- `audit:cleanup` -- daily at 02:00 (default 90-day retention)
- `sanctum:prune-expired --hours=48` -- daily
- `tasks:recover-stuck` -- every 5 minutes
- `projects:check-budgets` -- hourly
- `human-tasks:check-sla` -- every 5 minutes (escalate/expire overdue human tasks)
- `DispatchScheduledProjectsJob` -- every minute (evaluates due continuous projects)

### Seeders
- `SkillAndAgentSeeder` -- seeds default skills and agents (Step 6/7 of install)
- `PopularToolsSeeder` -- seeds 16 popular tools, all disabled by default (Step 7/7 of install)
- Both use `updateOrCreate` on composite keys for idempotency.

## Commands

```bash
# First-time setup
make install                                        # Docker: build + install wizard

# Development
docker compose up -d                                # Start services
docker compose exec app php artisan horizon         # Queue workers (runs in horizon container)
docker compose logs -f horizon                      # Watch queue processing

# Testing
docker compose exec app php artisan test            # Run tests

# Useful
docker compose exec app php artisan tinker          # REPL
docker compose exec app php artisan pail            # Tail logs

# Seeders (can be re-run safely)
docker compose exec app php artisan db:seed --class=PopularToolsSeeder    # Seed popular tools
docker compose exec app php artisan db:seed --class=SkillAndAgentSeeder   # Seed default skills & agents
```

## Docker Services

| Service | Image | Port | Purpose |
|---------|-------|------|---------|
| app | PHP 8.4-fpm (custom) | -- | Application (FPM) |
| nginx | nginx:1.27-alpine | 8080:80 | Web server |
| postgres | postgres:17-alpine | 5432 | Database |
| redis | redis:7-alpine | 6379 | Cache/Queue/Sessions |
| horizon | PHP 8.4-fpm (custom) | -- | Queue workers |
| scheduler | PHP 8.4-fpm (custom) | -- | Cron (schedule:run every 60s) |
| vite | PHP 8.4-fpm (custom) | 5173 | Frontend dev server |

## Environment Variables

Key variables (see `.env.example`):

```
DB_CONNECTION=pgsql              # PostgreSQL required
REDIS_CLIENT=predis              # Use predis, not phpredis
REDIS_DB=0                       # Queues
REDIS_CACHE_DB=1                 # Cache
REDIS_LOCK_DB=2                  # Locks
QUEUE_CONNECTION=redis           # Required for Horizon
SESSION_DRIVER=redis             # Redis-backed sessions

# LLM Provider Keys (at least one required for AI features)
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
GOOGLE_AI_API_KEY=

# Local Agents (auto-detected, no keys needed)
LOCAL_AGENTS_ENABLED=true
```

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.5
- laravel/fortify (FORTIFY) - v1
- laravel/framework (LARAVEL) - v12
- laravel/horizon (HORIZON) - v5
- laravel/mcp (MCP) - v0
- laravel/passport (PASSPORT) - v13
- laravel/prompts (PROMPTS) - v0
- laravel/reverb (REVERB) - v1
- laravel/sanctum (SANCTUM) - v4
- laravel/socialite (SOCIALITE) - v5
- livewire/livewire (LIVEWIRE) - v4
- larastan/larastan (LARASTAN) - v3
- laravel/boost (BOOST) - v2
- laravel/pail (PAIL) - v1
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- tailwindcss (TAILWINDCSS) - v4

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `mcp-development` — Use this skill for Laravel MCP development only. Trigger when creating or editing MCP tools, resources, prompts, or servers in Laravel projects. Covers: artisan make:mcp-* generators, mcp:inspector, routes/ai.php, Tool/Resource/Prompt classes, schema validation, shouldRegister(), OAuth setup, URI templates, read-only attributes, and MCP debugging. Do not use for non-Laravel MCP projects or generic AI features without MCP.
- `passport-development` — Develops OAuth2 API authentication with Laravel Passport. Activates when installing or configuring Passport; setting up OAuth2 grants (authorization code, client credentials, personal access tokens, device authorization); managing OAuth clients; protecting API routes with token authentication; defining or checking token scopes; configuring SPA cookie authentication; handling token lifetimes and refresh tokens; or when the user mentions Passport, OAuth2, API tokens, bearer tokens, or API authentication. Make sure to use this skill whenever the user works with OAuth2, API tokens, or third-party API access, even if they don't explicitly mention Passport.
- `socialite-development` — Manages OAuth social authentication with Laravel Socialite. Activate when adding social login providers; configuring OAuth redirect/callback flows; retrieving authenticated user details; customizing scopes or parameters; setting up community providers; testing with Socialite fakes; or when the user mentions social login, OAuth, Socialite, or third-party authentication.
- `livewire-development` — Use for any task or question involving Livewire. Activate if user mentions Livewire, wire: directives, or Livewire-specific concepts like wire:model, wire:click, wire:sort, or islands, invoke this skill. Covers building new components, debugging reactivity issues, real-time form validation, drag-and-drop, loading states, migrating from Livewire 3 to 4, converting component formats (SFC/MFC/class-based), and performance optimization. Do not use for non-Livewire reactive UI (React, Vue, Alpine-only, Inertia.js) or standard Laravel forms without Livewire.
- `tailwindcss-development` — Always invoke when the user's message includes 'tailwind' in any form. Also invoke for: building responsive grid layouts (multi-column card grids, product grids), flex/grid page structures (dashboards with sidebars, fixed topbars, mobile-toggle navs), styling UI components (cards, tables, navbars, pricing sections, forms, inputs, badges), adding dark mode variants, fixing spacing or typography, and Tailwind v3/v4 work. The core use case: writing or fixing Tailwind utility classes in HTML templates (Blade, JSX, Vue). Skip for backend PHP logic, database queries, API routes, JavaScript with no HTML/CSS component, CSS file audits, build tool configuration, and vanilla CSS.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan Commands

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`, `php artisan tinker --execute "..."`).
- Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Debugging

- Use the `database-query` tool when you only need to read from the database.
- Use the `database-schema` tool to inspect table structure before writing migrations or models.
- To execute PHP code for debugging, run `php artisan tinker --execute "your code here"` directly.
- To read configuration values, read the config files directly or run `php artisan config:show [key]`.
- To inspect routes, run `php artisan route:list` directly.
- To check environment variables, read the `.env` file directly.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - `public function __construct(public GitHub $github) { }`
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<!-- Explicit Return Types and Method Params -->
```php
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
```

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `php artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app/Console/Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allow to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `php artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `php artisan test --compact`.
- To run all tests in a file: `php artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `php artisan test --compact --filter=testName` (recommended after making a change to a related file).

</laravel-boost-guidelines>
