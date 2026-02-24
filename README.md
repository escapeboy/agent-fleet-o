# Agent Fleet - Community Edition

Self-hosted AI Agent Mission Control platform. Build, orchestrate, and monitor AI agent experiments with a visual pipeline, human-in-the-loop approvals, and full audit trail.

[![CI](https://github.com/escapeboy/agent-fleet-o/actions/workflows/ci.yml/badge.svg)](https://github.com/escapeboy/agent-fleet-o/actions/workflows/ci.yml)
[![License: AGPL v3](https://img.shields.io/badge/License-AGPL_v3-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.4-purple)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12-red)](https://laravel.com/)

## Screenshots

<table>
<tr>
<td width="50%">

**Dashboard**
KPI overview with active experiments, success rate, budget spend, and pending approvals.

<img src="screenshots/qa-dashboard.png" width="100%" alt="Dashboard">

</td>
<td width="50%">

**Agent Template Gallery**
Browse 14 pre-built agent templates across 5 categories. Search, filter by category, and deploy with one click.

<img src="screenshots/qa-agent-templates.png" width="100%" alt="Agent Templates">

</td>
</tr>
<tr>
<td>

**Agent LLM Configuration**
Per-agent provider and model selection with fallback chains. Supports Anthropic, OpenAI, Google, and local agents.

<img src="screenshots/agent-llm-edit-panel.png" width="100%" alt="Agent LLM Config">

</td>
<td>

**Agent Evolution**
AI-driven agent self-improvement. Analyze execution history, propose personality and config changes, and apply with one click.

<img src="screenshots/qa-evolution-tab.png" width="100%" alt="Agent Evolution">

</td>
</tr>
<tr>
<td>

**Crew Execution**
Live progress tracking during multi-agent crew execution. Each task shows its assigned skill, provider, and elapsed time.

<img src="screenshots/tasks-panel-building.png" width="100%" alt="Crew Execution">

</td>
<td>

**Task Output**
Expand any completed task to inspect the AI-generated output, including structured JSON responses.

<img src="screenshots/tasks-expanded-output.png" width="100%" alt="Task Output">

</td>
</tr>
<tr>
<td>

**Visual Workflow Builder**
DAG-based workflow editor with conditional branching, human tasks, switch nodes, and dynamic forks.

<img src="screenshots/qa-workflows.png" width="100%" alt="Workflows">

</td>
<td>

**Tool Management**
Manage MCP servers, built-in tools, and external integrations with risk classification and per-agent assignment.

<img src="screenshots/qa-tools.png" width="100%" alt="Tools">

</td>
</tr>
<tr>
<td>

**AI Assistant Sidebar**
Context-aware AI chat embedded in every page with 28 built-in tools for querying and managing the platform.

<img src="screenshots/assistant-sidebar.png" width="100%" alt="Assistant Sidebar">

</td>
<td>

**Experiment Detail**
Full experiment lifecycle view with timeline, tasks, transitions, artifacts, metrics, and outbound delivery.

<img src="screenshots/qa-experiment-detail.png" width="100%" alt="Experiment Detail">

</td>
</tr>
<tr>
<td>

**Settings & Webhooks**
Global platform settings, AI provider keys (BYOK), outbound connectors, and webhook configuration.

<img src="screenshots/settings-page-full.png" width="100%" alt="Settings">

</td>
<td>

**Error Handling**
Failed tasks display detailed error information including provider, error type, and request IDs for debugging.

<img src="screenshots/tasks-panel-error-expanded.png" width="100%" alt="Error Handling">

</td>
</tr>
</table>

## Features

- **Experiment Pipeline** -- 20-state machine with automatic stage progression (scoring, planning, building, approval, execution, metrics collection)
- **AI Agents** -- Configure agents with roles, goals, backstories, personality traits, and skill assignments
- **Agent Templates** -- 14 pre-built templates across 5 categories (engineering, content, business, design, research)
- **Agent Evolution** -- AI-driven self-improvement: analyze execution history, propose config changes, and apply improvements
- **Agent Crews** -- Multi-agent teams with lead/member roles and shared context
- **Skills** -- Reusable AI skill definitions (LLM, connector, rule, hybrid) with versioning and cost tracking
- **Playbooks** -- Sequential or parallel multi-step workflows combining skills
- **Workflows** -- Visual DAG builder with 8 node types: agent, conditional, human task, switch, dynamic fork, do-while loops
- **Projects** -- One-shot and continuous long-running agent projects with cron scheduling, budget caps, milestones, and overlap policies
- **Human-in-the-Loop** -- Approval queue and human task forms with SLA enforcement and escalation
- **Multi-Channel Outbound** -- Email (SMTP), Telegram, Slack, and webhook delivery with rate limiting
- **Webhooks** -- Inbound signal ingestion (HMAC-SHA256) and outbound webhook delivery with retry and event filtering
- **Budget Controls** -- Per-experiment and per-project credit ledger with pessimistic locking and auto-pause on overspend
- **Marketplace** -- Browse, publish, and install shared skills, agents, and workflows
- **REST API** -- 99 endpoints under `/api/v1/` with Sanctum auth, cursor pagination, and auto-generated OpenAPI 3.1 docs at `/docs/api`
- **MCP Server** -- 112 Model Context Protocol tools across 16 domains for LLM/agent access (stdio + HTTP/SSE)
- **Tool Management** -- MCP servers (stdio/HTTP), built-in tools (bash/filesystem/browser), risk classification, per-agent assignment
- **Credentials** -- Encrypted credential vault for external services with rotation, expiry tracking, and per-project injection
- **Testing** -- Regression test suites for agent outputs with automated evaluation
- **Local Agents** -- Run Codex and Claude Code as local execution backends (auto-detected, zero cost)
- **Audit Trail** -- Full activity logging with searchable, filterable audit log
- **AI Gateway** -- Provider-agnostic LLM access via PrismPHP with circuit breakers and fallback chains
- **BYOK** -- Bring your own API keys for Anthropic, OpenAI, or Google
- **Queue Management** -- Laravel Horizon with 6 priority queues and auto-scaling

## Quick Start (Docker)

```bash
git clone https://github.com/escapeboy/agent-fleet-o.git
cd agent-fleet
make install
```

This will:
1. Copy `.env.example` to `.env`
2. Build and start all Docker services
3. Run the interactive setup wizard (database, admin account, LLM provider)

Visit **http://localhost:8080** when complete.

## Quick Start (Manual)

Requirements: PHP 8.4+, PostgreSQL 17+, Redis 7+, Node.js 20+, Composer

```bash
git clone https://github.com/escapeboy/agent-fleet-o.git
cd agent-fleet
composer install
npm install && npm run build
cp .env.example .env
# Edit .env with your database and Redis credentials
php artisan app:install
php artisan horizon &
php artisan serve
```

## Configuration

All configuration is in `.env`. Key variables:

```bash
# Database (PostgreSQL required)
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_DATABASE=agent_fleet

# Redis (queues, cache, sessions, locks)
REDIS_HOST=redis
REDIS_DB=0          # Queues
REDIS_CACHE_DB=1    # Cache
REDIS_LOCK_DB=2     # Locks

# LLM Providers -- at least one required
ANTHROPIC_API_KEY=
OPENAI_API_KEY=
GOOGLE_AI_API_KEY=
```

Additional LLM keys can be configured in **Settings > AI Provider Keys** after login.

## Architecture

Built with Laravel 12, Livewire 4, and Tailwind CSS. Domain-driven design with 16 bounded contexts:

| Domain | Purpose |
|--------|---------|
| Agent | AI agent configs, execution, personality, evolution |
| Crew | Multi-agent teams with lead/member roles |
| Experiment | Pipeline, state machine, playbooks |
| Signal | Inbound data ingestion |
| Outbound | Multi-channel delivery |
| Approval | Human-in-the-loop reviews and human tasks |
| Budget | Credit ledger, cost enforcement |
| Metrics | Measurement, revenue attribution |
| Audit | Activity logging |
| Skill | Reusable AI skill definitions |
| Tool | MCP servers, built-in tools, risk classification |
| Credential | Encrypted external service credentials |
| Workflow | Visual DAG builder, graph executor |
| Project | Continuous/one-shot projects, scheduling |
| Assistant | Context-aware AI chat with 28 tools |
| Marketplace | Skill/agent/workflow sharing |

## Docker Services

| Service | Purpose | Port |
|---------|---------|------|
| app | PHP 8.4-fpm | -- |
| nginx | Web server | 8080 |
| postgres | PostgreSQL 17 | 5432 |
| redis | Cache/Queue/Sessions | 6379 |
| horizon | Queue workers | -- |
| scheduler | Cron jobs | -- |
| vite | Frontend dev server | 5173 |

## Common Commands

```bash
make start          # Start services
make stop           # Stop services
make logs           # Tail logs
make update         # Pull latest + migrate
make test           # Run tests
make shell          # Open app container shell
```

Or with Docker Compose directly:

```bash
docker compose exec app php artisan tinker       # REPL
docker compose exec app php artisan test          # Run tests
docker compose exec app php artisan migrate       # Run migrations
```

## Upgrading

```bash
make update
```

This pulls the latest code, rebuilds containers, runs migrations, and clears caches.

## Tech Stack

- **Framework:** Laravel 12 (PHP 8.4)
- **Database:** PostgreSQL 17
- **Cache/Queue:** Redis 7
- **Frontend:** Livewire 4 + Tailwind CSS 4 + Alpine.js
- **AI Gateway:** PrismPHP
- **Queue:** Laravel Horizon
- **Auth:** Laravel Fortify (2FA) + Sanctum (API tokens)
- **Audit:** spatie/laravel-activitylog
- **API Docs:** dedoc/scramble (OpenAPI 3.1)
- **MCP:** laravel/mcp (Model Context Protocol)

## Contributing

Contributions are welcome. Please open an issue first to discuss proposed changes.

1. Fork the repository
2. Create a feature branch (`git checkout -b feat/my-feature`)
3. Make your changes and add tests
4. Run `php artisan test` to verify
5. Submit a pull request

## License

Agent Fleet Community Edition is open-source software licensed under the [GNU Affero General Public License v3.0](LICENSE).
