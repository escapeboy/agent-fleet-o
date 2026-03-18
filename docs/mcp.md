# MCP Server

FleetQ exposes a [Model Context Protocol](https://modelcontextprotocol.io/) (MCP) server that gives AI agents full programmatic access to the platform. Any MCP-compatible client — Cursor, Claude Code, Codex, or a custom agent — can connect and use all 200+ tools.

## Transports

| Transport | Endpoint | Auth | Use case |
|-----------|----------|------|----------|
| HTTP/SSE | `POST /mcp` | Sanctum bearer token | Remote clients (Cursor, Claude Code remote, custom agents) |
| stdio | `php artisan mcp:start agent-fleet` | Auto (default team owner) | Local CLI agents on the same machine |

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

200+ tools across 31 domains. All tools are scoped to your team — no cross-tenant access is possible.

| Domain | Key tools |
|--------|-----------|
| **Agent** | `agent_list`, `agent_get`, `agent_create`, `agent_update`, `agent_toggle_status`, `agent_delete`, `agent_runtime_state`, `agent_config_history`, `agent_rollback` |
| **Experiment** | `experiment_list`, `experiment_get`, `experiment_create`, `experiment_start`, `experiment_pause`, `experiment_resume`, `experiment_retry`, `experiment_kill`, `experiment_steps` |
| **Workflow** | `workflow_list`, `workflow_create`, `workflow_generate` (AI from prompt), `workflow_validate`, `workflow_save_graph`, `workflow_estimate_cost`, `workflow_activate` |
| **Project** | `project_list`, `project_create`, `project_update`, `project_activate`, `project_pause`, `project_resume`, `project_trigger_run`, `project_archive` |
| **Crew** | `crew_list`, `crew_create`, `crew_execute`, `crew_execution_status`, `crew_executions_list` |
| **Skill** | `skill_list`, `skill_create`, `skill_update`, `guardrail`, `multi_model_consensus`, `code_execution`, `browser_skill` |
| **Tool** | `tool_list`, `tool_create`, `tool_discover_mcp`, `tool_import_mcp`, `tool_activate`, `tool_deactivate` |
| **Credential** | `credential_list`, `credential_create`, `credential_rotate` |
| **Approval** | `approval_list`, `approval_approve`, `approval_reject`, `approval_complete_human_task` |
| **Signal** | `signal_list`, `signal_ingest`, `contact_manage`, `intent_score`, `kg_search` |
| **Outbound** | `connector_config_list`, `connector_config_save`, `connector_config_test` |
| **Budget** | `budget_summary`, `budget_check`, `budget_forecast` |
| **Memory** | `memory_search`, `memory_list_recent`, `memory_delete`, `memory_upload_knowledge` |
| **Artifact** | `artifact_list`, `artifact_get`, `artifact_content`, `artifact_download_info` |
| **Trigger** | `trigger_rule_list`, `trigger_rule_create`, `trigger_rule_update`, `trigger_rule_test` |
| **Assistant** | `assistant_send_message`, `assistant_conversation_list`, `assistant_conversation_get` |
| **Marketplace** | `marketplace_browse`, `marketplace_install`, `marketplace_publish` |
| **Integration** | `integration_list`, `integration_connect`, `integration_execute` |
| **Shared** | `team_get`, `team_update`, `team_members`, `api_token_manage`, `team_byok_credential_manage` |
| **System** | `system_health`, `system_dashboard_kpis`, `system_audit_log` |

---

## Security

- **Authentication**: Every HTTP request requires a valid Sanctum bearer token. Requests without a token receive `401 Unauthorized`.
- **Tenant isolation**: All tools enforce team scope — an agent can only read and modify data belonging to its own team.
- **Token scope**: Tokens are scoped to `team:<id>`. Wildcard `*` tokens are rejected for non-super-admin users.
- **Token expiry**: API tokens expire after 30 days by default. Refresh before expiry or create a non-expiring token from the UI.
- **stdio safety**: The stdio transport is local-only and auto-authenticates — never expose the artisan process over a network socket.

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
