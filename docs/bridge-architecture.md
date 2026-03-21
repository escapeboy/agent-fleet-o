# FleetQ Bridge — Architecture & Protocol Reference

## Overview

FleetQ Bridge is a Go binary that runs on a team member's machine and connects it to the FleetQ cloud platform as a compute endpoint. It enables the platform to execute LLM requests, agent tasks, and MCP tool calls on the bridge machine.

## Components

```
┌─────────────────────────────────────────────────────────────┐
│                    FleetQ Cloud (Laravel)                     │
│                                                               │
│  BridgeController    BridgeRouter    BridgeRequestRegistry    │
│  (REST API)          (routing)       (Redis req/resp)         │
│       │                   │                │                  │
│       └───────────────────┼────────────────┘                  │
│                           │                                   │
│                    Reverb (WebSocket)                          │
│                    private-daemon.{teamId}                     │
└──────────────────────────┬────────────────────────────────────┘
                           │ WSS (Pusher protocol)
                           │
┌──────────────────────────┴────────────────────────────────────┐
│                    Bridge Daemon (Go)                          │
│                                                               │
│  relay/daemon.go      llm/router.go      mcp/manager.go      │
│  (WebSocket)          (LLM dispatch)     (MCP servers)        │
│       │                   │                    │              │
│       │              ┌────┼────┐          ┌────┼────┐         │
│       │              │    │    │          │    │    │         │
│       │           Ollama  LM   CLI     Play  FS   Git        │
│       │                Studio  Agents  wright               │
│       │                        │                             │
│       │                   claude-code                         │
│       │                   codex                               │
│       │                   gemini                              │
└───────┼──────────────────────────────────────────────────────┘
```

## Protocol Flow

### 1. Authentication & Registration

```
Bridge                           FleetQ API
  │                                 │
  │  POST /api/v1/bridge/register   │
  │  {session_id, bridge_version,   │
  │   endpoints, label}             │
  │────────────────────────────────>│
  │                                 │  Creates/updates BridgeConnection
  │  201 {session_id, team_id,      │  model in PostgreSQL
  │   reverb: {app_key, relay_url}} │
  │<────────────────────────────────│
  │                                 │
```

- `session_id` = `bridge-{nanosecondTimestamp}` (generated client-side)
- Registration is idempotent — reconnections with same session_id update the existing record
- Empty endpoints on reconnect: Bridge carries forward previous connection's endpoints

### 2. WebSocket Connection (Pusher Protocol)

```
Bridge                           Reverb Server
  │                                 │
  │  WSS /app/{appKey}?protocol=7   │
  │────────────────────────────────>│
  │                                 │
  │  pusher:connection_established  │
  │  {socket_id: "..."}            │
  │<────────────────────────────────│
  │                                 │
  │  POST /api/v1/broadcasting/auth │  (HMAC-SHA256 auth)
  │────────────────────────────────>│
  │  {auth: "key:signature"}        │
  │<────────────────────────────────│
  │                                 │
  │  pusher:subscribe               │
  │  {channel: private-daemon.{tid}}│
  │────────────────────────────────>│
  │                                 │
  │  pusher_internal:               │
  │    subscription_succeeded       │
  │<────────────────────────────────│
```

### 3. Request Dispatch

When FleetQ needs to run something on the bridge:

```
FleetQ                    Redis              Bridge (via WebSocket)
  │                         │                      │
  │  RPUSH bridge:req:{tid} │                      │
  │  {request_id, frame_type,                      │
  │   payload}              │                      │
  │────────────────────────>│                      │
  │                         │                      │
  │                    Reverb relays to WebSocket   │
  │                         │  agent.request       │
  │                         │─────────────────────>│
  │                         │                      │
  │                         │                      │  Route to backend:
  │                         │                      │  - agent_key → CLI agent
  │                         │                      │  - server+method → MCP
  │                         │                      │  - provider+model → LLM
  │                         │                      │
  │                         │  client-relay.chunk   │
  │                         │<─────────────────────│  (streaming chunks)
  │                         │                      │
  │  BLPOP bridge:stream:   │  client-relay.chunk   │
  │  {requestId}            │  {done: true}        │
  │<────────────────────────│<─────────────────────│
```

### 4. Heartbeat Loop

```
Bridge                           FleetQ API
  │                                 │
  │  POST /api/v1/bridge/heartbeat  │  (every 30s)
  │  {session_id}                   │
  │────────────────────────────────>│
  │                                 │  Updates last_seen_at
  │  200 {alive: true}             │
  │<────────────────────────────────│
```

### 5. Endpoint Refresh Loop

```
Bridge                           FleetQ API
  │                                 │
  │  (probe Ollama, LM Studio,      │  (every 60s)
  │   check CLI agents)             │
  │                                 │
  │  POST /api/v1/bridge/endpoints  │
  │  {session_id, endpoints: {      │
  │    agents: [...],               │
  │    llm_endpoints: [...],        │
  │    mcp_servers: [...]           │
  │  }}                             │
  │────────────────────────────────>│
  │                                 │  Updates BridgeConnection.endpoints
  │  200 {updated: true}           │
  │<────────────────────────────────│
```

## Frame Types

Used in the Redis request/response protocol:

| Frame Type | Hex | Direction | Purpose |
|-----------|-----|-----------|---------|
| LLM Chunk | 0x0002 | bridge → cloud | Streaming LLM response chunk |
| LLM End | 0x0003 | bridge → cloud | Final LLM chunk with usage stats |
| Agent Event | 0x0011 | bridge → cloud | Agent output event |
| Agent End | 0x0012 | bridge → cloud | Agent completion |
| MCP Tool Call | 0x0020 | cloud → bridge | MCP tool call request |
| MCP Result | 0x0021 | bridge → cloud | MCP tool call result |
| Error | 0x00FF | bridge → cloud | Error response |

## Endpoints Data Structure

Stored as JSONB in `bridge_connections.endpoints`:

```json
{
  "agents": [
    {
      "key": "claude-code",
      "name": "Claude Code",
      "found": true,
      "version": ""
    },
    {
      "key": "codex",
      "name": "Codex",
      "found": true,
      "version": ""
    }
  ],
  "llm_endpoints": [
    {
      "name": "Ollama",
      "url": "http://localhost:11434",
      "base_url": "http://localhost:11434",
      "type": "ollama",
      "models": ["llama3.2", "mistral", "codellama"],
      "model_count": 3,
      "online": true
    }
  ],
  "mcp_servers": [
    {"name": "playwright"},
    {"name": "filesystem"}
  ],
  "ide_mcp_configs": []
}
```

## BridgeRouter — Request Routing

`App\Domain\Bridge\Services\BridgeRouter` resolves which bridge connection should handle a request.

### Routing Modes

- **auto** (default): Picks the highest-priority active bridge that has the requested agent/model
- **per_agent**: Routes each agent to a specific bridge (configured per-agent preference)
- **prefer**: Always prefers a specific bridge, falls back to others if unavailable

### Resolution Methods

| Method | Use Case |
|--------|----------|
| `resolveForAgent($teamId, $agentKey)` | Find best bridge with a specific agent |
| `resolveForMcpServer($teamId, $serverName)` | Find bridge with a specific MCP server |
| `allAvailableAgents($teamId)` | Flattened list across all bridges (with bridge_id) |
| `uniqueAvailableAgents($teamId)` | Deduplicated by agent key |
| `activeConnections($teamId)` | All active bridges ordered by priority |

## Race Conditions & Edge Cases

### Relay calls endpoints before register

The relay binary may call `POST /bridge/endpoints` with empty `[]` BEFORE the daemon calls `POST /bridge/register`. This is handled by:

1. If no active connection exists, `UpdateBridgeEndpoints` caches endpoints in Redis (`bridge:pending_endpoints:{teamId}`, TTL 60s)
2. `RegisterBridgeConnection` checks for pending endpoints in Redis and applies them

### Endpoint carryover on reconnect

When a bridge reconnects with empty endpoints (before discovery completes), `RegisterBridgeConnection` copies endpoints from the most recently disconnected connection for the same team.

### Heartbeat timeout

Connections without a heartbeat for >5 minutes are considered stale. The `agents:health-check` command can be used to detect and clean up dead connections.

## Configuration Files

### Bridge side

| File | Purpose |
|------|---------|
| `~/.fleetq/bridge.json` | API token, server URL, LLM endpoint URLs |
| `~/.fleetq/mcp.json` | MCP server definitions (command, args, env) |

### Server side

| Config | Key | Default | Purpose |
|--------|-----|---------|---------|
| `config/bridge.php` | `relay_enabled` | `false` | Enable relay infrastructure |
| `config/bridge.php` | `relay_port` | `8070` | Relay HTTP port |
| `config/bridge.php` | `relay_mcp_url` | `http://localhost:8070` | Relay MCP proxy URL |
| `config/reverb.php` | `apps.0.key` | — | Pusher app key for WebSocket |
| `config/reverb.php` | `apps.0.secret` | — | HMAC secret for channel auth |

## Database Schema

### `bridge_connections` table

| Column | Type | Purpose |
|--------|------|---------|
| `id` | uuid (PK) | UUIDv7 |
| `team_id` | uuid (FK) | Owning team |
| `session_id` | varchar(255) | Bridge session identifier |
| `label` | varchar(100) | Human-readable name |
| `priority` | integer | Routing priority (lower = higher) |
| `status` | varchar | `connected`, `disconnected`, `reconnecting` |
| `bridge_version` | varchar(50) | Bridge binary version |
| `endpoints` | jsonb | Agents, LLMs, MCP servers (see above) |
| `ip_address` | varchar | Bridge machine's IP |
| `user_agent` | varchar | — |
| `connected_at` | timestamp | When connection was established |
| `last_seen_at` | timestamp | Last heartbeat time |
| `disconnected_at` | timestamp | When connection was terminated |

## MCP Tools (Platform Side)

| Tool | Purpose |
|------|---------|
| `bridge_status` | Connection status, LLM/agent/MCP counts, routing mode |
| `bridge_list` | List all agents across active bridges |
| `bridge_endpoint_list` | Show LLM endpoints and agents per bridge |
| `bridge_endpoint_toggle` | Enable/disable individual endpoints |
| `bridge_disconnect` | Disconnect a bridge by ID |
| `bridge_rename` | Set custom label for a bridge |
| `bridge_set_routing` | Configure routing mode and per-agent preferences |
