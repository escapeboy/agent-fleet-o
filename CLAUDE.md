# Agent Fleet - Community Edition

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
- **Docker:** PHP 8.4-fpm-alpine + Nginx 1.27 + PostgreSQL 17 + Redis 7

## Project Structure

Domain-driven design with 15 bounded contexts:

```
app/
  Domain/                        # Business logic by domain
    Agent/                       # AI agent management & execution
      Actions/                   # CreateAgent, ExecuteAgent, GenerateAgentName, DisableAgent, HealthCheck
      Enums/                     # AgentStatus
      Models/                    # Agent (SoftDeletes, role/goal/backstory, skills), AiRun, AgentExecution
    Crew/                        # Multi-agent teams
      Actions/                   # CreateCrew, UpdateCrew, ExecuteCrew, DecomposeGoal, SynthesizeResult, ValidateTaskOutput
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
      Services/                  # ArtifactContentResolver, StepOutputBroadcaster
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
    Approval/                    # Human-in-the-loop
      Actions/                   # CreateApprovalRequest, Approve, Reject, ExpireStaleApprovals
      Enums/                     # ApprovalStatus
      Models/                    # ApprovalRequest
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
      Actions/                   # CreateWorkflow, UpdateWorkflow, DeleteWorkflow, ValidateWorkflowGraph, EstimateWorkflowCost, MaterializeWorkflow
      Enums/                     # WorkflowNodeType (start/end/agent/conditional), WorkflowStatus (draft/active/archived)
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
    Marketplace/                 # Skill, agent & workflow marketplace
      Actions/                   # PublishToMarketplace, InstallFromMarketplace
      Enums/                     # MarketplaceStatus, ListingVisibility
      Models/                    # MarketplaceListing, MarketplaceInstallation, MarketplaceReview
    Shared/                      # Cross-domain
      Enums/                     # TeamRole
      Models/                    # Team, TeamProviderCredential
      Notifications/             # WeeklyDigest, Welcome
      Scopes/                    # TeamScope
      Traits/                    # BelongsToTeam
  Infrastructure/
    AI/                          # Provider-agnostic LLM gateway
      Contracts/                 # AiGatewayInterface, AiMiddlewareInterface
      DTOs/                      # AiRequestDTO, AiResponseDTO, AiUsageDTO
      Gateways/                  # PrismAiGateway (BYOK credentials), FallbackAiGateway, LocalAgentGateway
      Middleware/                # RateLimiting, BudgetEnforcement, IdempotencyCheck, SchemaValidation, UsageTracking
      Models/                    # LlmRequestLog, CircuitBreakerState
      Services/                  # CircuitBreaker, ProviderResolver, LocalAgentDiscovery
  Http/Controllers/              # SignalWebhookController, TrackingController, ArtifactPreviewController
  Http/Controllers/Api/V1/      # 17 REST API controllers (95 endpoints)
  Http/Middleware/               # SetCurrentTeam
  Livewire/                      # Admin panel components
    Dashboard/                   # DashboardPage
    Experiments/                 # List, Detail, Create, Timeline, TasksPanel, ExecutionLog, Transitions, Outbound, Metrics, Artifacts, WorkflowProgress
    Approvals/                   # ApprovalInboxPage
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
    Teams/                       # TeamSettingsPage (BYOK + API tokens)
  Console/Commands/              # AgentHealthCheck, AggregateMetrics, ExpireStaleApprovals, PollInputConnectors,
                                 # SendWeeklyDigest, CleanupAuditEntries, CheckProjectBudgets, RecoverStuckTasks, InstallCommand
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

### API v1 Routes (`/api/v1/`)

95 endpoints across 17 controllers, Sanctum bearer token auth, cursor pagination, OpenAPI 3.1 docs at `/docs/api`.

| Group | Endpoints | Purpose |
|-------|-----------|---------|
| Auth | `POST token`, `POST refresh`, `DELETE token`, `GET devices`, `DELETE devices/{id}` | Token management |
| Me | `GET /me`, `PUT /me` | Current user |
| Experiments | `GET`, `GET {id}`, `POST`, `POST {id}/transition`, `POST {id}/pause`, `POST {id}/resume`, `POST {id}/retry`, `POST {id}/kill`, `POST {id}/retry-from-step`, `GET {id}/steps` | Experiment CRUD + transitions + actions |
| Projects | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/activate`, `POST {id}/pause`, `POST {id}/resume`, `POST {id}/restart`, `POST {id}/trigger`, `GET {id}/runs` | Project CRUD + lifecycle + runs |
| Agents | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `PATCH {id}/status` | Agent CRUD + toggle |
| Skills | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `GET {id}/versions` | Skill CRUD + versions |
| Tools | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}` | Tool CRUD |
| Credentials | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/rotate` | Credential CRUD + secret rotation |
| Crews | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `POST {id}/execute`, `GET {id}/executions`, `GET {id}/executions/{eid}` | Crew CRUD + execution |
| Workflows | `GET`, `GET {id}`, `POST`, `PUT {id}`, `DELETE {id}`, `PUT {id}/graph`, `POST {id}/validate`, `POST {id}/activate`, `POST {id}/duplicate`, `GET {id}/cost` | Workflow CRUD + graph ops |
| Signals | `GET`, `GET {id}`, `POST` | Signal CRUD |
| Approvals | `GET`, `GET {id}`, `POST {id}/approve`, `POST {id}/reject` | Approval management |
| Marketplace | `GET`, `GET {slug}`, `POST`, `POST {slug}/install`, `POST {slug}/reviews` | Marketplace browse + publish |
| Team | `GET`, `PUT`, `GET members`, `DELETE members/{id}`, `GET credentials`, `POST credentials`, `DELETE credentials/{id}`, `GET tokens`, `POST tokens`, `DELETE tokens/{id}` | Team management + BYOK |
| Dashboard | `GET /dashboard` | KPI summary |
| Health | `GET /health` | System health |
| Audit | `GET /audit` | Audit log |
| Budget | `GET /budget` | Budget summary |

### Legacy API Routes (`/api/`)

| Method | Path | Controller | Auth | Purpose |
|--------|------|------------|------|---------|
| `POST` | `/api/signals/webhook` | SignalWebhookController | HMAC | Signal ingestion |
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
- 70 migrations.

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
