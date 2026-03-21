# MCP Server

FleetQ exposes a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server that gives AI agents full programmatic access to the platform. Any MCP-compatible client — Cursor, Claude Code, Codex, or a custom agent — can connect and use all 268+ tools.

## Transports

| Transport | Endpoint | Auth | Use case |
|-----------|----------|------|----------|
| HTTP/SSE | `POST /mcp` | Sanctum bearer token (or OAuth2 in cloud) | Remote clients (Cursor, Claude Code remote, Claude.ai, ChatGPT) |
| HTTP/SSE (Full) | `POST /mcp/full` | Sanctum bearer token (or OAuth2 in cloud) | Power users needing all 268+ tools (no consolidation) |
| stdio | `php artisan mcp:start agent-fleet` | Auto (default team owner) | Local CLI agents on the same machine |

### Compact vs Full server

The **compact** endpoint (`/mcp`) consolidates 268+ tools into ~33 meta-tools using an `action` parameter. This is designed for clients with tool count limits (Claude.ai, ChatGPT). The **full** endpoint (`/mcp/full`) exposes every tool individually.

---

## HTTP/SSE — Remote Access

### Step 1 — Get a Sanctum token

```bash
curl -X POST https://your-domain.com/api/v1/auth/token \
  -H "Content-Type: application/json" \
  -d '{"email": "you@example.com", "password": "your-password", "device_name": "my-agent"}'
```

Response:

```json
{
  "token": "1|abc123xyz...",
  "expires_at": "2026-04-17T12:00:00.000000Z",
  "user": { "id": "...", "name": "...", "email": "..." }
}
```

Tokens expire after **30 days**. Refresh with:

```bash
curl -X POST https://your-domain.com/api/v1/auth/refresh \
  -H "Authorization: Bearer 1|abc123xyz..."
```

Alternatively, create a long-lived token from the UI at **Team Settings → API Tokens**.

### Step 2 — Connect your client

#### Cursor

Add to `.cursor/mcp.json` (or global `~/.cursor/mcp.json`):

```json
{
  "mcpServers": {
    "fleetq": {
      "url": "https://your-domain.com/mcp",
      "headers": {
        "Authorization": "Bearer 1|abc123xyz..."
      }
    }
  }
}
```

#### Claude Code

Add to `~/.claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "fleetq": {
      "type": "http",
      "url": "https://your-domain.com/mcp",
      "headers": {
        "Authorization": "Bearer 1|abc123xyz..."
      }
    }
  }
}
```

#### Claude.ai Desktop (Cloud Edition)

Cloud Edition supports OAuth2 (Authorization Code + PKCE). Add FleetQ as an MCP integration in Claude.ai — no manual token management required.

#### Custom agent (Python example)

```python
import httpx

MCP_URL = "https://your-domain.com/mcp"
TOKEN = "1|abc123xyz..."

headers = {
    "Authorization": f"Bearer {TOKEN}",
    "Content-Type": "application/json",
}

# List available tools
resp = httpx.post(MCP_URL, headers=headers, json={
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list",
    "params": {}
})

# Call a tool
resp = httpx.post(MCP_URL, headers=headers, json={
    "jsonrpc": "2.0",
    "id": 2,
    "method": "tools/call",
    "params": {
        "name": "experiment_list",
        "arguments": {"status": "executing", "limit": 10}
    }
})
```

---

## stdio — Local Access

For agents running on the same machine as FleetQ (no token required):

```bash
php artisan mcp:start agent-fleet
```

The server auto-authenticates as the default team owner. Connect your local client to this stdio process.

#### Claude Code (local)

Add to `~/.claude/claude_desktop_config.json`:

```json
{
  "mcpServers": {
    "agent-fleet": {
      "type": "stdio",
      "command": "php",
      "args": ["/path/to/fleetq/artisan", "mcp:start", "agent-fleet"]
    }
  }
}
```

#### Codex

Add to `~/.codex/config.toml`:

```toml
[[mcp_servers]]
name = "agent-fleet"
command = ["php", "/path/to/fleetq/artisan", "mcp:start", "agent-fleet"]
```

---

## Available Tools

268+ tools across 37 domains. All tools are scoped to your team — no cross-tenant access is possible.

| Domain | Key tools |
|--------|-----------|
| **Agent** | `agent_list`, `agent_get`, `agent_create`, `agent_update`, `agent_toggle_status`, `agent_delete`, `agent_config_history`, `agent_rollback`, `agent_runtime_state`, `agent_skill_sync`, `agent_tool_sync`, `agent_templates_list` |
| **Experiment** | `experiment_list`, `experiment_get`, `experiment_create`, `experiment_start`, `experiment_pause`, `experiment_resume`, `experiment_retry`, `experiment_kill`, `experiment_valid_transitions`, `experiment_retry_from_step`, `experiment_steps`, `experiment_cost`, `experiment_share` |
| **Workflow** | `workflow_list`, `workflow_create`, `workflow_generate` (AI from prompt), `workflow_validate`, `workflow_save_graph`, `workflow_estimate_cost`, `workflow_activate`, `workflow_duplicate`, `workflow_suggestion`, `workflow_time_gate`, `workflow_execution_chain` |
| **Project** | `project_list`, `project_create`, `project_update`, `project_activate`, `project_pause`, `project_resume`, `project_trigger_run`, `project_archive` |
| **Crew** | `crew_list`, `crew_get`, `crew_create`, `crew_update`, `crew_execute`, `crew_execution_status`, `crew_executions_list` |
| **Skill** | `skill_list`, `skill_create`, `skill_update`, `skill_versions`, `guardrail`, `multi_model_consensus`, `code_execution`, `browser_skill` |
| **Tool** | `tool_list`, `tool_create`, `tool_update`, `tool_delete`, `tool_activate`, `tool_deactivate`, `tool_discover_mcp`, `tool_import_mcp`, `tool_ssh_fingerprints`, `tool_bash_policy` |
| **Credential** | `credential_list`, `credential_create`, `credential_update`, `credential_rotate`, `credential_oauth_initiate`, `credential_oauth_finalize` |
| **Approval** | `approval_list`, `approval_approve`, `approval_reject`, `approval_complete_human_task`, `approval_webhook_config` |
| **Signal** | `signal_list`, `signal_ingest`, `signal_get`, `connector_binding`, `contact_manage`, `imap_mailbox`, `email_reply`, `http_monitor`, `alert_connector`, `slack_connector`, `ticket_connector`, `clearcue_connector`, `intent_score`, `kg_search`, `kg_entity_facts`, `kg_add_fact`, `connector_subscription`, `inbound_connector_manage`, `connector_binding_delete` |
| **Outbound** | `connector_config_list`, `connector_config_get`, `connector_config_save`, `connector_config_delete`, `connector_config_test` |
| **Budget** | `budget_summary`, `budget_check`, `budget_forecast` |
| **Marketplace** | `marketplace_browse`, `marketplace_publish`, `marketplace_install`, `marketplace_review`, `marketplace_categories`, `marketplace_analytics` |
| **Memory** | `memory_search`, `memory_list_recent`, `memory_stats`, `memory_delete`, `memory_upload_knowledge` |
| **Artifact** | `artifact_list`, `artifact_get`, `artifact_content`, `artifact_download_info` |
| **Webhook** | `webhook_list`, `webhook_create`, `webhook_update`, `webhook_delete` |
| **Trigger** | `trigger_rule_list`, `trigger_rule_create`, `trigger_rule_update`, `trigger_rule_delete`, `trigger_rule_test` |
| **Integration** | `integration_list`, `integration_connect`, `integration_disconnect`, `integration_ping`, `integration_execute`, `integration_capabilities` |
| **Assistant** | `assistant_conversation_list`, `assistant_conversation_get`, `assistant_send_message`, `assistant_conversation_clear` |
| **Email** | `email_theme_list/get/create/update/delete`, `email_template_list/get/create/update/delete/generate` |
| **Chatbot** | `chatbot_list/get/create/update/toggle_status/session_list/analytics_summary/learning_entries` |
| **Bridge** | `bridge_status`, `bridge_list`, `bridge_endpoint_list`, `bridge_endpoint_toggle`, `bridge_disconnect`, `bridge_rename`, `bridge_set_routing` |
| **Telegram** | `telegram_bot_manage` |
| **Shared** | `notification_manage`, `team_get`, `team_update`, `team_members`, `local_llm`, `team_byok_credential_manage`, `custom_endpoint_manage`, `api_token_manage` |
| **Cache** | `semantic_cache_stats`, `semantic_cache_purge` |
| **Evolution** | `evolution_proposal_list`, `evolution_analyze`, `evolution_apply`, `evolution_reject` |
| **System** | `system_dashboard_kpis`, `system_health`, `system_version_check`, `system_audit_log`, `global_settings_update` |
| **Compute** | `compute_manage` |
| **RunPod** | `runpod_manage` |
| **Git** | `git_status`, `git_log`, `git_diff`, `git_branches`, etc. |

---

## Compact Tool Pattern (for Claude.ai / ChatGPT)

The compact server consolidates related tools into meta-tools with an `action` parameter:

```json
{
  "name": "agent_manage",
  "arguments": {
    "action": "list",
    "limit": 10
  }
}
```

Each meta-tool supports multiple actions. For example, `agent_manage` supports: `list`, `get`, `create`, `update`, `toggle_status`, `delete`, `config_history`, `rollback`, `skill_sync`, `tool_sync`, `feedback_submit`, `feedback_list`, `feedback_stats`, `runtime_state`.

---

## Tool Profiles

Teams can customize which tools are available via profiles:

| Profile | Tools | Best for |
|---------|-------|----------|
| `essential` | 11 core tools | Simple agents with focused tasks |
| `standard` | 33 tools (core + operations) | Most use cases |
| `full` | All 268+ tools | Power users and automation |
| `custom` | Hand-picked | Teams with specific needs |

Configure via **Team Settings → MCP Tools** or the `team_update` MCP tool.

---

## Bridge Integration

FleetQ Bridge extends MCP capabilities by allowing the platform to proxy MCP tool calls to servers running on bridge-connected machines. See [FleetQ Bridge documentation](/docs/bridge) for setup.

When a Bridge is connected with MCP servers configured (`~/.fleetq/mcp.json`), those servers become available for platform use via the `POST /api/v1/bridge/mcp-call` endpoint.

---

## Security

- **Authentication**: Every HTTP request requires a valid Sanctum bearer token (or OAuth2 token in cloud). Requests without a token receive `401 Unauthorized`.
- **Tenant isolation**: All tools enforce team scope — an agent can only read and modify data belonging to its own team.
- **Token scope**: Tokens are scoped to `team:<id>`. Wildcard `*` tokens are rejected for non-super-admin users.
- **Token expiry**: API tokens expire after 30 days by default. Refresh before expiry or create a non-expiring token from the UI.
- **stdio safety**: The stdio transport is local-only and auto-authenticates — never expose the artisan process over a network socket.
- **DNS rebinding protection**: The HTTP transport validates `Origin` headers against an allowlist (configurable via `MCP_ALLOWED_ORIGINS`).

---

## Token Management

List active tokens:

```bash
curl https://your-domain.com/api/v1/auth/devices \
  -H "Authorization: Bearer 1|abc123xyz..."
```

Revoke a token:

```bash
curl -X DELETE https://your-domain.com/api/v1/auth/token \
  -H "Authorization: Bearer 1|abc123xyz..."
```

Revoke a specific token by ID:

```bash
curl -X DELETE https://your-domain.com/api/v1/auth/devices/42 \
  -H "Authorization: Bearer 1|abc123xyz..."
```
