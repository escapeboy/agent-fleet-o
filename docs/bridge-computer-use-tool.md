# Desktop Computer Use via a Bridge agent

FleetQ runs in the cloud and has no desktop of its own, so it cannot drive a
native GUI directly. It *can*, however, attach an external MCP server to a
**Bridge agent** — an AI coding agent (Claude Code, Codex, Gemini CLI) running
on a user's own machine through [FleetQ Bridge](https://get.fleetq.net).

[`open-computer-use`](https://github.com/iFurySt/open-codex-computer-use) is an
open-source (MIT) MCP server that exposes desktop computer use — it drives
native apps on macOS, Linux, and Windows through the OS Accessibility tree
rather than raw screen pixels. Because it is a plain `stdio` MCP server, FleetQ
can use it today with **no platform code** — only a Tool registration.

## Scope

- **Works for:** Bridge / local agents (the agent and the desktop are the same
  machine).
- **Does NOT work for:** cloud Prism agents or `claude-code-vps` agents — those
  run headless with no desktop session. This is a Bridge-only capability by
  nature.

## Setup

### 1. Install the MCP server on the host machine

On the machine running the Bridge agent:

```bash
npm i -g open-computer-use
```

On macOS, run it once and grant **Accessibility** and **Screen Recording**
permissions. Windows and Linux need no extra step.

```bash
open-computer-use            # triggers the macOS permission prompts
open-computer-use doctor     # verify permissions are granted
```

### 2. Register it as a FleetQ Tool

Create a Tool of type `mcp_stdio` (UI: **Tools → New**, or
`POST /api/v1/tools`):

| Field | Value |
|-------|-------|
| Type | `mcp_stdio` |
| Command | `open-computer-use` |
| Args | `["mcp"]` |

### 3. Attach the Tool to the agent

Add the Tool to a Bridge-backed agent via the `agent_tool` pivot
(**Agent → Tools**). When that agent next executes, the Bridge writes the MCP
server into the agent's runtime config and the desktop computer-use tools
become available to the model.

## Tools exposed

`open-computer-use` provides an Accessibility-tree-centric tool surface:

| Tool | Purpose |
|------|---------|
| `list_apps` | Enumerate running / recently used apps |
| `get_app_state` | Launch/reuse an app; return its accessibility tree (indexed elements + screenshot) |
| `click`, `perform_secondary_action` | Act on an element by index |
| `scroll`, `drag` | Pointer gestures |
| `type_text`, `press_key`, `set_value` | Keyboard / value input |

The model acts on **element indices** from the accessibility tree, not pixel
coordinates — more reliable and far cheaper on tokens than screenshot-only
computer use.

## Verifying the Tool

Use the built-in MCP debug command to confirm the server responds before
wiring it to an agent:

```bash
php artisan mcp:call list_apps --server=agent-fleet   # FleetQ's own tools
```

To exercise `open-computer-use` directly, its own CLI has an equivalent:

```bash
open-computer-use call list_apps
open-computer-use call get_app_state --args '{"app":"TextEdit"}'
```

## Security notes

- Desktop computer use grants an agent full control of the host GUI. Only
  attach this Tool to agents and teams you trust.
- `open-computer-use` is a ~100% AI-generated project. It is fine to *use* as an
  external MCP server; do not vendor its source into FleetQ without review.
