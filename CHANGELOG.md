# Changelog

All notable changes to Agent Fleet Community Edition are documented here.

## [Unreleased]

### Added
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
- PHPStan baseline regenerated for all new domains and RLS security files
- Laravel Pint style issues across feature branches (SmtpEmailConnector, RLS middleware, bootstrap/app.php, RLS migration, RLS test)

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
