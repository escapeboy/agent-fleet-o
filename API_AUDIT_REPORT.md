# API Endpoint Audit Report
## agent-fleet-open Platform Features vs REST API

**Audit Date:** 2026-04-02  
**Scope:** Verify all UI-accessible features have corresponding API endpoints

---

## EXECUTIVE SUMMARY

- **38 API Controllers** registered in `app/Http/Controllers/Api/V1/`
- **35 Livewire component directories** with UI implementations
- **CRITICAL GAPS FOUND:** 6 features with UI but NO REST API
- **NEW FEATURES STATUS:** ToolTemplates partially covered (MCP only), QuickAgent UI-only, Screenpipe/1Password not implemented

---

## CRITICAL GAPS: UI-Only Features (No REST API)

### 1. **EVALUATION** 
- **Has Livewire?** YES (`app/Livewire/Evaluation/`)
- **Has API Controller?** NO
- **Has Domain?** YES (`app/Domain/Evaluation/`)
- **Missing Endpoints:**
  - `GET /evaluations` (list)
  - `POST /evaluations` (create evaluation run)
  - `GET /evaluations/{id}`
  - `DELETE /evaluations/{id}`
  - `POST /evaluations/{id}/result` (store result)
- **SEVERITY:** HIGH (core feature)

### 2. **HOOKS** (Agent Lifecycle Hooks)
- **Has Livewire?** YES (`app/Livewire/Hooks/`)
- **Has API Controller?** NO
- **Has Domain?** Likely in Agent domain
- **Missing Endpoints:**
  - `GET /agents/{agent}/hooks`
  - `POST /agents/{agent}/hooks` (create)
  - `PUT /agents/{agent}/hooks/{hook}`
  - `DELETE /agents/{agent}/hooks/{hook}`
  - `GET /agents/{agent}/hooks/{hook}/executions`
- **SEVERITY:** HIGH (Phase 2 feature from today's session)

### 3. **PROFILE** (User Profile Management)
- **Has Livewire?** YES (`app/Livewire/Profile/`)
- **Has API Controller?** NO (partial via `AuthController::me/updateMe`)
- **Missing Endpoints:**
  - `PATCH /me/profile` (preferences)
  - `PATCH /me/settings` (workspace settings)
  - `GET /me/preferences`
- **SEVERITY:** MEDIUM

### 4. **SETUP** (Onboarding Wizard)
- **Has Livewire?** YES (`app/Livewire/Setup/`)
- **Has API Controller?** NO
- **Missing Endpoints:**
  - `POST /setup/initialize`
  - `GET /setup/status`
  - `POST /setup/complete-step`
- **SEVERITY:** LOW (one-time)

### 5. **CHANGELOG** (Release Notes)
- **Has Livewire?** YES (`app/Livewire/Changelog/`)
- **Has API Controller?** NO
- **Missing Endpoints:**
  - `GET /changelog` (list)
  - `GET /changelog/{version}`
- **SEVERITY:** LOW (informational)

### 6. **TELEGRAM** (Telegram Integration UI)
- **Has Livewire?** YES (`app/Livewire/Telegram/`)
- **Has API Controller?** NO (may use `IntegrationController`)
- **Missing Endpoints:**
  - `GET /integrations/telegram/settings`
  - `POST /integrations/telegram/setup`
  - `POST /integrations/telegram/disconnect`
- **SEVERITY:** MEDIUM

---

## TODAY'S SESSION: NEW FEATURES API COVERAGE

### FEATURE 1: ToolTemplates (GPU Tool Templates)
**Status:** ⚠️ INCOMPLETE

| Component | Status | Location |
|-----------|--------|----------|
| Domain Model | ✅ | `app/Domain/Tool/Models/ToolTemplate.php` |
| Livewire UI | ✅ | `app/Livewire/Tools/ToolTemplateCatalogPage.php` |
| Action | ✅ | `app/Domain/Tool/Actions/DeployToolTemplateAction.php` |
| MCP Tools | ✅ | `app/Mcp/Tools/Tool/ToolTemplateManageTool.php` |
| REST API Controller | ❌ | MISSING |
| API Routes | ❌ | NOT in `routes/api_v1.php` |

**Missing Endpoints:**
- `GET /tool-templates` (list)
- `GET /tool-templates/{slug}`
- `POST /tool-templates/{slug}/deploy`
- `GET /tool-templates/{slug}/cost-estimate`
- `GET /tool-templates/categories`

**Action Required:** Create `ToolTemplateController`

### FEATURE 2: QuickAgent (Rapid Agent Creation)
**Status:** ❌ UI-ONLY

- **Livewire:** ✅ `app/Livewire/Agents/QuickAgentForm.php`
- **API:** ⚠️ Only standard `POST /agents` via `AgentController`
- **Gap:** No dedicated quick-create endpoint or flag

### FEATURE 3: Screenpipe Connector
**Status:** ❌ NOT FOUND

- No Domain directory
- No Integration registered
- No API Controller
- No Livewire components

### FEATURE 4: 1Password Integration
**Status:** ❌ NOT FOUND

- No Domain directory
- No Integration registered
- No API Controller
- No Livewire components

### FEATURE 5: McpMarketplace
**Status:** ✅ COVERED

- **Domain:** ✅ `app/Domain/Marketplace/`
- **Livewire:** ✅ `app/Livewire/Marketplace/`
- **API:** ✅ `MarketplaceController` with public + authenticated endpoints
- **Routes:** ✅ `routes/api_v1.php` (lines 68-75, 230-232)

---

## DOMAIN-BY-DOMAIN ANALYSIS

### ✅ COMPLETE (Domain + UI + API)
- **Agent** - CRUD + feedback, runtime, config-history, rollback
- **Approval** - CRUD + approve, reject, escalate
- **Assistant** - Conversations CRUD + messages, review, annotate
- **Audit** - Read-only list
- **Bridge** - HTTP tunnel, WebSocket relay, MCP routing
- **Budget** - Read-only dashboard
- **Chatbot** - CRUD instances + tokens, conversations
- **Credential** - CRUD + rotate, versions, rollback
- **Crew** - CRUD + execute, execution list/show
- **Evolution** - Proposal management (apply, reject)
- **Experiment** - Full CRUD + lifecycle (pause, resume, kill, retry)
- **GitRepository** - CRUD + test, list files/PRs
- **Integration** - Connect/disconnect + ping, execute, capabilities
- **Knowledge** - CRUD knowledge bases + graph entities/facts
- **Marketplace** - List/show (public) + publish, install, review (auth)
- **Memory** - CRUD + stats, search (no PUT)
- **Metrics** - Read-only (index, aggregations, comparisons)
- **Outbound** - CRUD connectors + test
- **Project** - CRUD + activate, pause, resume, trigger, runs
- **Signal** - CRUD (lightweight, no PUT)
- **Skill** - CRUD + versions, benchmarks
- **Trigger** - CRUD + toggleStatus, test
- **VoiceSession** - CRUD + token issuance, transcript
- **Webhook** - CRUD endpoints
- **Workflow** - CRUD + graph, validate, activate, duplicate, cost, export/import

### ⚠️ INCOMPLETE (Has UI/Domain, Partial API)

| Domain | Coverage | Gap |
|--------|----------|-----|
| **Email** | Templates + Themes | Missing outbound settings, logs |
| **Tool** | Core tools + Federation | Missing ToolTemplates controller |
| **System** | Config management only | No general endpoints |

### ❌ MISSING (Has UI/Domain, No API)

| Domain | Severity |
|--------|----------|
| **Evaluation** | HIGH |
| **Hooks** | HIGH |
| **Profile** | MEDIUM |
| **Telegram** | MEDIUM |
| **Setup** | LOW |
| **Changelog** | LOW |

---

## STANDARD CRUD COMPLIANCE

### ✅ Full CRUD (GET, POST, PUT/PATCH, DELETE)
Agent, Approval, Artifact, Assistant, Chatbot, Credential, Crew, Experiment, GitRepository, KnowledgeBase, Project, Skill, Tool, Trigger, Workflow, OutboundConnector, EmailTemplate/Theme, Team

### ⚠️ Limited CRUD
- **Memory** - no PUT/PATCH
- **Signal** - no PUT/PATCH (lightweight)
- **Evolution** - read-only (proposal-based)
- **Integration** - integration-specific (not standard)
- **Marketplace** - read + custom operations

### 📖 Read-Only
Audit, Health, Dashboard, Metrics, Budget, ProviderConfig, LangfuseConfig

---

## SUMMARY OF ACTION ITEMS

### PRIORITY 1 (This Week)
1. **Create ToolTemplateController**
   - `GET /tool-templates`
   - `GET /tool-templates/{slug}`
   - `POST /tool-templates/{slug}/deploy`
   - `GET /tool-templates/{slug}/cost-estimate`
   - `GET /tool-templates/categories`

2. **Create EvaluationController**
   - Full CRUD
   - `POST /{id}/result`
   - `GET /{id}/metrics`

3. **Create AgentHookController**
   - Nested under agents: `GET|POST /agents/{agent}/hooks`
   - Full CRUD on individual hooks

### PRIORITY 2 (Next Sprint)
- Extend Email API (outbound settings, logs)
- Create ToolMiddlewareController
- Enhance Profile endpoints

### PRIORITY 3 (Backlog)
- Screenpipe integration (domain + API)
- 1Password integration (domain + API)
- Setup/onboarding flow API

---

## Key Metrics

| Metric | Count |
|--------|-------|
| Total API Controllers | 38 |
| Livewire Components | 35 |
| Domains | 32 |
| Fully Covered | 22 |
| Partially Covered | 3 |
| UI-Only (Gaps) | 6 |
| Not Implemented | 2 (Screenpipe, 1Password) |

