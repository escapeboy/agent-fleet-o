# Screenpipe Research Report

**Date:** 2026-04-02
**Researcher:** Claude (automated deep research)
**Subject:** https://github.com/screenpipe/screenpipe
**Stars:** ~18k | **Commits:** 7,638 | **License:** MIT

---

## Executive Summary

Screenpipe is an open-source, privacy-first "AI memory for your screen" — a desktop application that continuously captures screen content and audio, processes them locally via OCR and speech-to-text, stores everything in a local SQLite database, and exposes it through a REST API and MCP server. It enables AI agents to act on what the user has seen, heard, and done. The project is written primarily in Rust (core) with a Tauri desktop app (Rust + TypeScript). It has a plugin system called "Pipes" — scheduled AI agents defined as simple markdown files that can query screen history and take automated actions.

**Key differentiator:** Event-driven capture (not continuous frame recording) + accessibility tree-first text extraction + local-only storage + agent-ready API surface. The pipe system is remarkably simple — a single `pipe.md` file with a YAML frontmatter schedule and a natural language prompt.

**Relevance to FleetQ:** High. Screenpipe is a complementary data source that FleetQ agents could consume via its MCP server or REST API. Several architectural patterns are worth studying.

---

## Architecture Overview

### Tech Stack

| Layer | Technology |
|-------|-----------|
| Core engine | Rust (Cargo workspace with multiple crates) |
| Desktop app | Tauri (Rust backend + TypeScript/React frontend) |
| Database | SQLite with FTS5 full-text search |
| OCR | OS accessibility tree (primary), Apple Vision / Windows native / Tesseract (fallback) |
| Speech-to-text | Whisper (local) or Deepgram (cloud) |
| API | REST on localhost:3030 (Rust/Axum) |
| MCP server | TypeScript (npx screenpipe-mcp, stdio transport) |
| Plugin runtime | External coding agent (pi or claude-code) executing markdown prompts |

### Crate Structure

| Crate | Responsibility |
|-------|---------------|
| `screenpipe-vision` | Screen capture, OCR fallback |
| `screenpipe-accessibility` | OS accessibility tree walking (buttons, labels, text fields) |
| `screenpipe-audio` | Audio capture, chunking (default 30s) |
| `screenpipe-db` | SQLite storage, FTS5 indexing |
| `screenpipe-core` | Pipe management, scheduling |
| `screenpipe-server` | REST API server (Axum) |
| `screenpipe-integrations/screenpipe-mcp` | MCP server (TypeScript/Node) |

### Data Flow

```
OS Events (app switch, click, typing pause, scroll, clipboard)
    |
    v
Event-driven Capture (screenshot + accessibility tree)
    |
    v
Processing (accessibility text / OCR fallback / Whisper STT)
    |
    v
SQLite Storage (~/.screenpipe/db.sqlite) + Media Files (~/.screenpipe/data/)
    |
    v
REST API (localhost:3030) + MCP Server (stdio)
    |
    v
Pipes (scheduled AI agents) / External AI tools (Claude, Cursor, etc.)
```

### Event-Driven Capture (Key Innovation)

Instead of recording every second, screenpipe listens for meaningful OS events:

| Event | Trigger |
|-------|---------|
| App switch | New window gained focus |
| Click/scroll | User interacted with UI |
| Typing pause | User stopped typing (debounced) |
| Clipboard copy | Content copied |
| Idle fallback | Periodic capture every ~5s when nothing happens |

Each capture pairs a screenshot with the **accessibility tree** — the structured text the OS already knows about (buttons, labels, text fields). This is faster and more accurate than OCR. OCR is only used as a fallback for remote desktops, games, and some Linux apps.

### Processing Engines

| Engine | Type | Platform | When Used |
|--------|------|----------|-----------|
| Accessibility tree | Text extraction | macOS, Windows | Primary — every capture |
| Apple Vision | OCR | macOS | Fallback when accessibility empty |
| Windows native | OCR | Windows | Fallback when accessibility empty |
| Tesseract | OCR | Linux | Primary (accessibility varies) |
| Whisper | Speech-to-text | All (local) | Audio transcription |
| Deepgram | Speech-to-text | Cloud API | Optional cloud audio |

Additional: speaker identification, PII redaction, frame deduplication (skips identical frames).

### Resource Usage

- CPU: 5-10%
- RAM: 0.5-3 GB
- Storage: ~20 GB/month (~300 MB/8hr with event-driven vs ~2 GB with continuous)
- Works fully offline

---

## Key Features

### 1. Screen & Audio Recording
- 24/7 local capture of all monitors
- Audio from multiple input/output devices
- Speaker identification and diarization
- PII redaction built-in

### 2. Search & Retrieval
- Full-text search via FTS5
- Filter by: app name, window title, content type (OCR/audio/input/accessibility), time range, speaker
- Natural language search via AI integration
- Raw SQL access to underlying SQLite database

### 3. Plugin System (Pipes)
- Each pipe is a single `pipe.md` file with YAML frontmatter + natural language prompt
- Schedule formats: `every 30m`, `every 2h`, `daily`, cron expressions
- Screenpipe prepends a context header (time range, timezone, API URL, output dir) before execution
- Runs via external coding agent (pi or claude-code) — the agent queries the screenpipe API
- Built-in examples: Toggl time tracking, Obsidian daily journal, standup reports, idea tracker, Apple Reminders
- Managed via REST API or desktop app UI
- `.env` files for secrets per pipe

### 4. MCP Server
- Package: `screenpipe-mcp` (npm, stdio transport)
- Install: `npx -y screenpipe-mcp`
- Works with Claude Desktop, Claude Code, Cursor, Cline, Continue, Windsurf, OpenCode, Gemini CLI
- Tools:
  - `search-content` — search screen/audio/input/accessibility with filters (q, content_type, limit, offset, start_time, end_time, app_name, window_name, min_length, max_length, include_frames, speaker_ids, speaker_name)
  - `export-video` — create video exports from screen recordings for a time range

### 5. REST API (localhost:3030)
- **Context Retrieval:** GET /search (full-text + filters), GET /search/keyword (keyword + grouping)
- **System:** GET /health (pipeline metrics)
- **Audio Control:** List devices, start/stop processing, start/stop recording per device
- **Vision Control:** List monitors, get vision status/permissions, get pipeline metrics
- **Content Management:** Add/remove tags, raw SQL queries, add content (frames/transcriptions)
- **Frames:** Access individual frames
- **Pipes:** Install, enable/disable, run, list pipes via HTTP API

### 6. Desktop App (Tauri)
- Timeline view of screen history
- Search interface
- Pipe management UI
- AI settings (model/provider presets per pipe)
- Cloud sync settings

### 7. Cloud & Teams (Pro)
- Cloud sync between devices ($39/month or included in Lifetime+Pro)
- Cloud archive
- Teams: shared configs, shared pipes, per-pipe AI data permissions, admin dashboard, MDM ready (Intune/SCCM)
- Custom pricing for enterprise

### 8. Integrations
ChatGPT, Apple Intelligence, Ollama, Claude Code, OpenCode, OpenClaw, Cline, Continue, Gemini CLI, Obsidian, Msty

---

## Comparison with Alternatives

| Feature | Screenpipe | Rewind/Limitless | Microsoft Recall | Granola |
|---------|-----------|-----------------|-----------------|---------|
| Open source | MIT | No | No | No |
| Platforms | macOS, Windows, Linux | macOS, Windows | Windows only | macOS only |
| Data storage | 100% local | Cloud required | Local (Windows) | Cloud |
| Multi-monitor | All monitors | Active window only | Yes | Meetings only |
| Audio transcription | Local Whisper | Yes | No | Cloud |
| Developer API | Full REST + SDK + MCP | Limited | No | No |
| Plugin system | Pipes (AI agents) | No | No | No |
| AI model choice | Any (local or cloud) | Proprietary | Microsoft AI | Proprietary |
| Team deployment | Central config, AI perms | No | No | No |
| Pricing | $400 one-time | Subscription | Bundled Windows | Subscription |

---

## Integration Opportunities for FleetQ

### High Value (Recommend)

#### 1. Screenpipe as a Bridge Data Source
FleetQ agents could consume screenpipe data via its MCP server or REST API to gain "user context awareness" — knowing what the user is currently working on, what meetings they attended, what apps they used. This transforms FleetQ from a pure orchestration platform into a context-aware one.

**Implementation:** Add screenpipe as a Tool type (MCP stdio) in FleetQ's Tool domain. Users install screenpipe locally, then FleetQ agents can query it via the bridge.

#### 2. Screenpipe MCP via FleetQ Bridge
The FleetQ bridge relay already discovers local MCP servers. Screenpipe's MCP server (`npx -y screenpipe-mcp`) could be auto-discovered or manually registered as a bridge endpoint, giving cloud-hosted FleetQ agents access to local screen data.

**Implementation:** Add screenpipe-mcp to `LocalAgentDiscovery` detection or let users register it as an MCP tool with `mcp_stdio` type.

#### 3. Activity-Triggered Workflows
Screenpipe's event data (app switches, typing, meetings) could feed into FleetQ's Signal domain as a new connector type (ScreenpipeConnector). This enables workflows triggered by user activity — "when I open Figma, start the design review crew" or "after a Zoom meeting ends, generate notes and create tasks."

**Implementation:** New `ScreenpipeConnector` in Signal domain that polls `localhost:3030/search` on schedule or listens for specific app/window patterns.

### Medium Value (Consider)

#### 4. Pipe-Style Agent Definitions
Screenpipe's pipe.md format (YAML frontmatter + natural language prompt = scheduled AI agent) is remarkably developer-friendly. FleetQ could adopt a similar "agent-as-markdown" pattern for simple, single-purpose agents that don't need the full workflow DAG.

#### 5. Accessibility Tree Data for UI Automation
Screenpipe captures structured accessibility data (buttons, labels, text fields). This data could power FleetQ agents that interact with desktop apps — understanding what's on screen without needing browser automation.

#### 6. Meeting Intelligence Pipeline
Screenpipe's audio transcription + speaker identification could feed a FleetQ meeting intelligence workflow: auto-transcribe -> extract action items -> create tasks -> follow up.

### Low Value (Skip for Now)

#### 7. Screen Recording for Audit/Compliance
Using screenpipe recordings as an audit trail for agent actions. Interesting but niche.

#### 8. Rebuilding Screenpipe Functionality
No value in rebuilding what screenpipe does — it's MIT-licensed, well-maintained (7,600+ commits, 18k stars), and handles the hard OS-level capture work.

---

## Ideas Worth Borrowing

### 1. Event-Driven Capture Pattern
Screenpipe's approach of listening for meaningful events rather than polling on a fixed interval is highly efficient. FleetQ could apply this to Signal processing — instead of polling RSS/webhooks on fixed schedules, use event-driven triggers where possible.

### 2. Agent-as-Markdown (pipe.md)
The pipe.md format is brilliant in its simplicity: YAML frontmatter (schedule, enabled, permissions) + a natural language prompt = a complete scheduled agent. FleetQ could offer a simplified "Quick Agent" creation mode using this pattern — just paste a prompt and set a schedule. Under the hood, FleetQ would still use its full workflow engine, but the UX would be as simple as writing a markdown file.

### 3. Context Header Injection
Before executing a pipe, screenpipe prepends a context header with time range, timezone, API URL, and output directory. FleetQ already does something similar with its system prompt injection, but the explicit "context header" pattern (structured metadata prepended to every agent execution) is worth formalizing.

### 4. Accessibility-First Data Extraction
Using the OS accessibility tree as the primary text extraction method (with OCR as fallback) is smarter than pure OCR. If FleetQ ever builds desktop automation capabilities, this should be the approach.

### 5. One-File Plugin System
The entire pipe is one file in a known directory. No registration, no compilation, no deployment. Drop a file, it runs. FleetQ's workflow system is more powerful but also more complex. A "drop-in agent" pattern (single YAML/MD file in `~/.fleetq/agents/`) could be a great developer experience addition.

### 6. Local-First with Cloud Optional
Screenpipe's architecture is local-first with cloud as an optional layer. FleetQ's bridge architecture already follows this pattern. Worth reinforcing: the bridge is the right approach for connecting cloud orchestration to local user context.

---

## Pricing Model

| Tier | Price | Features |
|------|-------|----------|
| Lifetime | $400 one-time | All features, all future updates |
| Lifetime + Pro 1yr | $600 one-time | Lifetime + 1 year cloud sync + priority support |
| Pro | $39/month | Cloud sync, priority support, pro AI models |
| Teams | Custom | Shared configs, shared pipes, AI data permissions, admin dashboard, MDM |

---

## Recommendations

### Verdict: INTEGRATE (do not rebuild)

1. **Register screenpipe-mcp as a supported Tool type** in FleetQ. Document how users can connect their local screenpipe instance to FleetQ agents via the bridge. This is the lowest-effort, highest-value integration.

2. **Build a ScreenpipeConnector** in the Signal domain to ingest screen activity as signals. This enables activity-triggered workflows — one of the most compelling use cases.

3. **Adopt the pipe.md pattern** as a "Quick Agent" creation mode in FleetQ. Users paste a prompt, set a schedule, and FleetQ handles the rest. This dramatically lowers the barrier to creating simple scheduled agents.

4. **Do NOT rebuild** any of screenpipe's core functionality (screen capture, OCR, audio transcription). It's MIT-licensed, mature, and handles complex OS-level work. Treat it as infrastructure.

5. **Long-term:** Explore a first-party FleetQ pipe for screenpipe that syncs activity data to FleetQ's knowledge graph, giving all agents persistent user context memory.

---

## Sources

1. GitHub Repository: https://github.com/screenpipe/screenpipe (18k stars, 7,638 commits, MIT license)
2. Official Documentation: https://docs.screenpi.pe
3. Architecture Docs: https://docs.screenpi.pe/architecture
4. Pipes Guide: https://docs.screenpi.pe/pipes
5. MCP Server Guide: https://docs.screenpi.pe/mcp-server
6. API Reference: https://docs.screenpi.pe/api-reference/context-retrieval/search-screen-and-audio-content-with-various-filters
7. Developer Guide: https://docs.screenpi.pe/for-developers
8. README (raw): https://raw.githubusercontent.com/screenpipe/screenpipe/main/README.md
