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
- **Docker:** PHP 8.4-fpm-alpine + Nginx 1.27 + PostgreSQL 17 + Redis 7

## Project Structure

Domain-driven design with clear boundaries:

```
app/
  Domain/                        # Business logic by domain
    Agent/                       # AI agent management & execution
      Actions/                   # CreateAgent, ExecuteAgent, GenerateAgentName, DisableAgent, HealthCheck
      Enums/                     # AgentStatus
      Models/                    # Agent (SoftDeletes, role/goal/backstory, skills), AiRun, AgentExecution
    Experiment/                  # Core experiment pipeline & state machine
      Actions/                   # Create, Transition, Kill, Pause, Resume
      Enums/                     # ExperimentStatus (20 states), ExperimentTrack, StageType, StageStatus, ExecutionMode
      Events/                    # ExperimentTransitioned
      Listeners/                 # DispatchNextStageJob, NotifyOnCriticalTransition, RecordTransitionMetrics
      Models/                    # Experiment, ExperimentStage, ExperimentStateTransition, PlaybookStep
      Pipeline/                  # BaseStageJob + 7 stage jobs, ExecutePlaybookStepJob, PlaybookExecutor
      States/                    # ExperimentStateMachine, ExperimentTransitionMap
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
      Enums/                     # SkillType, SkillStatus, RiskLevel, ExecutionType
      Models/                    # Skill, SkillVersion, SkillExecution
      Services/                  # SchemaValidator, SkillCostCalculator
    Marketplace/                 # Skill & agent marketplace
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
      Gateways/                  # PrismAiGateway (BYOK credentials), FallbackAiGateway
      Middleware/                # RateLimiting, BudgetEnforcement, IdempotencyCheck, SchemaValidation, UsageTracking
      Models/                    # LlmRequestLog, CircuitBreakerState
      Services/                  # CircuitBreaker, ProviderResolver
  Http/Controllers/              # SignalWebhookController, TrackingController, Api controllers
  Http/Middleware/               # SetCurrentTeam
  Livewire/                      # Admin panel components
    Dashboard/                   # DashboardPage
    Experiments/                 # List, Detail, Create, Timeline, Transitions, Outbound, Metrics, Artifacts
    Approvals/                   # ApprovalInboxPage
    Audit/                       # AuditLogPage
    Settings/                    # GlobalSettingsPage
    Health/                      # HealthPage
    Skills/                      # List, Detail, Create
    Agents/                      # List, Detail, Create
    Marketplace/                 # Browse, Detail, Publish
    Teams/                       # TeamSettingsPage (BYOK + API tokens)
  Console/Commands/              # AgentHealthCheck, AggregateMetrics, ExpireStaleApprovals, PollInputConnectors,
                                 # SendWeeklyDigest, CleanupAuditEntries, InstallCommand
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
| `GET /approvals` | ApprovalInboxPage | approvals.index |
| `GET /health` | HealthPage | health |
| `GET /audit` | AuditLogPage | audit |
| `GET /settings` | GlobalSettingsPage | settings |
| `GET /skills` | SkillListPage | skills.index |
| `GET /skills/create` | CreateSkillForm | skills.create |
| `GET /skills/{skill}` | SkillDetailPage | skills.show |
| `GET /agents` | AgentListPage | agents.index |
| `GET /agents/create` | CreateAgentForm | agents.create |
| `GET /agents/{agent}` | AgentDetailPage | agents.show |
| `GET /marketplace` | MarketplaceBrowsePage | marketplace.index |
| `GET /marketplace/publish` | PublishForm | marketplace.publish |
| `GET /marketplace/{listing:slug}` | MarketplaceDetailPage | marketplace.show |
| `GET /team` | TeamSettingsPage | team.settings |

### API Routes

| Method | Path | Controller | Auth | Purpose |
|--------|------|------------|------|---------|
| `POST` | `/api/signals/webhook` | SignalWebhookController | HMAC | Signal ingestion |
| `GET` | `/api/track/click` | TrackingController | -- | Click tracking (302 redirect) |
| `GET` | `/api/track/pixel` | TrackingController | -- | Open tracking (1x1 pixel) |
| `GET` | `/api/experiments` | ExperimentApiController | Sanctum | List experiments |
| `GET` | `/api/experiments/{id}` | ExperimentApiController | Sanctum | Show experiment |
| `GET` | `/api/signals` | SignalApiController | Sanctum | List signals |
| `POST` | `/api/signals` | SignalApiController | Sanctum | Create signal |

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
- 42 migrations.

### State Machine
- Custom implementation (NOT spatie/laravel-model-states).
- `ExperimentTransitionMap::canTransition()` validates all transitions.
- `transitionTo()` uses `SELECT FOR UPDATE` within a DB transaction.
- Side effects in event listeners, not in the transition itself.

### Pipeline
- Event-driven advancement (NOT job chains).
- `ExperimentTransitioned` event triggers 5 listeners:
  1. `DispatchNextStageJob` -- advances to next pipeline stage
  2. `RecordTransitionMetrics` -- records timing/metrics
  3. `NotifyOnCriticalTransition` -- alerts on failures
  4. `PauseOnBudgetExceeded` -- auto-pause on low budget
  5. `LogExperimentTransition` -- audit trail
- All stage jobs extend `BaseStageJob`.
- Job middleware: `CheckKillSwitch`, `CheckBudgetAvailable`, `TenantRateLimit`.

### Queue Architecture
- 6 queues: `critical`, `ai-calls`, `experiments`, `outbound`, `metrics`, `default`
- Redis: DB 0 (queues), DB 1 (cache), DB 2 (locks)

### AI Gateway
- Provider-agnostic via PrismPHP.
- Middleware pipeline: RateLimiting, BudgetEnforcement, IdempotencyCheck, SchemaValidation, UsageTracking.
- Circuit breaker per provider.
- Fallback chains: anthropic -> openai, openai -> anthropic, google -> anthropic.
- Supported: Anthropic (Claude), OpenAI (GPT-4o), Google (Gemini).

### Scheduled Commands
- `approvals:expire-stale` -- hourly
- `agents:health-check` -- every 5 minutes
- `metrics:aggregate --period=hourly` -- hourly
- `metrics:aggregate --period=daily` -- daily at 01:00
- `connectors:poll --driver=rss` -- every 15 minutes
- `digest:weekly` -- weekly
- `audit:cleanup` -- daily at 02:00 (default 90-day retention)

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
```
