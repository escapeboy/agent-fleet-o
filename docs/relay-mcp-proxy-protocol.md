# Relay MCP Proxy Protocol

## Overview

The relay binary needs a new HTTP endpoint that proxies MCP tool calls to bridge
daemons via their existing WebSocket connections. This enables the platform to
execute MCP tools (Playwright, filesystem, etc.) that run on the team's bridge
machine.

## Architecture

```
Laravel App                    Relay Binary                    Bridge Daemon
    │                              │                               │
    │  POST /mcp/call              │                               │
    │  {server, method, params}    │                               │
    │─────────────────────────────>│                               │
    │                              │  WebSocket message            │
    │                              │  {type: "mcp_tool_call", ...} │
    │                              │──────────────────────────────>│
    │                              │                               │
    │                              │                    MCP server │
    │                              │                    stdio call │
    │                              │                               │
    │                              │  WebSocket message            │
    │                              │  {type: "mcp_tool_result",...}│
    │                              │<──────────────────────────────│
    │                              │                               │
    │  200 OK                      │                               │
    │  {result: {content: [...]}}  │                               │
    │<─────────────────────────────│                               │
```

## Relay HTTP Endpoint

### `POST /mcp/call`

Accepts an MCP method call and proxies it to the bridge daemon identified by
`session_id`.

**Request:**
```json
{
    "team_id": "019cb001-f7d9-70f9-a68a-e884316619b0",
    "session_id": "relay-019cb001-1774086566",
    "server": "playwright",
    "method": "tools/call",
    "params": {
        "name": "browser_navigate",
        "arguments": {
            "url": "https://example.com"
        }
    },
    "timeout": 60
}
```

**Headers:**
- `X-Bridge-Session: relay-019cb001-1774086566` — identifies the WebSocket connection
- `X-Request-Id: <uuid>` — for request correlation and logging

**Response (success):**
```json
{
    "result": {
        "content": [
            {
                "type": "text",
                "text": "Navigated to https://example.com"
            }
        ]
    }
}
```

**Response (error from MCP server):**
```json
{
    "error": {
        "code": -32602,
        "message": "Tool 'browser_navigate' failed: timeout"
    }
}
```

**Response (bridge not connected):**
```
HTTP 404
{
    "error": "Bridge session not found or disconnected"
}
```

**Response (timeout):**
```
HTTP 504
{
    "error": "Bridge MCP call timed out after 60s"
}
```

## WebSocket Message Protocol

### Relay → Bridge Daemon: `mcp_tool_call`

```json
{
    "type": "mcp_tool_call",
    "request_id": "550e8400-e29b-41d4-a716-446655440000",
    "server": "playwright",
    "method": "tools/call",
    "params": {
        "name": "browser_navigate",
        "arguments": {
            "url": "https://example.com"
        }
    },
    "timeout": 60
}
```

### Bridge Daemon → Relay: `mcp_tool_result`

**Success:**
```json
{
    "type": "mcp_tool_result",
    "request_id": "550e8400-e29b-41d4-a716-446655440000",
    "result": {
        "content": [
            {
                "type": "text",
                "text": "Navigated to https://example.com"
            }
        ]
    }
}
```

**Error:**
```json
{
    "type": "mcp_tool_result",
    "request_id": "550e8400-e29b-41d4-a716-446655440000",
    "error": {
        "code": -32602,
        "message": "Tool not found: browser_navigate"
    }
}
```

## Bridge Daemon Implementation

When the bridge daemon receives an `mcp_tool_call` message:

1. Find the MCP server process by `server` name (already managed as child processes)
2. If `method` is `tools/call`:
   a. The MCP server should already be initialized (handshake done at startup)
   b. Send JSON-RPC `tools/call` to the server's stdin
   c. Read the response from stdout
   d. Wrap in `mcp_tool_result` and send back via WebSocket
3. If `method` is `tools/list`:
   a. Send JSON-RPC `tools/list` to the server's stdin
   b. Return the tools array

**Important:** MCP servers should be kept alive (persistent processes) rather than
spawned per-call. The bridge daemon already manages MCP server lifecycles. Each
server needs to maintain its handshake state so that `tools/call` can be sent
directly without re-initializing.

## Timeout Handling

- The relay should enforce the `timeout` from the HTTP request
- If the bridge daemon doesn't respond within `timeout` seconds, return HTTP 504
- The bridge daemon should pass `timeout` to its MCP server call
- The Laravel side adds +10s to the timeout for network overhead

## Security

- The relay validates `session_id` matches an active WebSocket connection
- The relay validates `team_id` matches the team that owns the WebSocket session
- MCP server names are validated against the bridge's registered `mcp_servers` list
- No arbitrary command execution — only MCP protocol methods are proxied

## Configuration

The relay needs these config additions:

```yaml
# Enable MCP proxy endpoint
mcp_proxy:
  enabled: true
  # HTTP port for the proxy endpoint (same as relay HTTP port)
  # Laravel calls this port to proxy MCP calls
```

The Laravel app uses:
```env
BRIDGE_RELAY_MCP_URL=http://localhost:8070
```
