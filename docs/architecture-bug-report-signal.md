# Architecture: Agent-Optimized Bug Report Processing

## Overview

Enriches bug reports with resolved stack traces, route-to-file mappings, suspect file rankings, and structured agent instructions — so agents delegated to fix bugs start at the right file immediately.

## New DB Tables

### `source_maps`
Per-team, per-project, per-release storage for uploaded JavaScript source maps.
```
id (uuid), team_id, project (string), release (string), map_data (jsonb), created_at, updated_at
Unique: (team_id, project, release)
```

### `route_maps`
Per-team, per-project route→controller mapping registered by projects at deploy time.
```
id (uuid), team_id, project (string), release (string), routes (jsonb), created_at, updated_at
Unique: (team_id, project) — latest registration wins
```

### `bug_report_project_configs`
Per-project agent instructions (test/lint/build commands, source directory, framework notes).
```
id (uuid), team_id, project (string), config (jsonb), created_at, updated_at
Unique: (team_id, project)
```

## New Domain Classes

### Services (`Domain/Signal/Services/`)
- **`StackTraceParser`** — regex-based parser for JS error stack frames (`at fn (file.js:L:C)`)
- **`SourceMapResolver`** — PHP-native VLQ decoder + source map lookup; resolves (file, line, col) → (origFile, origLine, origCol, fn)
- **`SuspectFilesAnalyzer`** — aggregates evidence from resolved stack, route map, network log, Livewire data; ranks by confidence

### Actions (`Domain/Signal/Actions/`)
- **`UploadSourceMapAction`** — validates + upserts a source map entry
- **`RegisterRouteMapAction`** — validates + upserts a route map entry
- **`ResolveStackTraceAction`** — extracts errors from signal payload, resolves frames, writes `resolved_errors` back to payload
- **`AnalyzeSuspectFilesAction`** — builds ranked `suspect_files` list from all available evidence, writes to payload

### Job (`Domain/Signal/Jobs/`)
- **`EnrichBugReportJob`** — dispatched after signal creation; runs `ResolveStackTraceAction` then `AnalyzeSuspectFilesAction`; queue: `default`

## Signal Payload Enrichment

All enriched data is stored in `signal.payload` (existing JSONB column). No schema migration needed for the signal itself.

```json
{
  "resolved_errors": [...],
  "suspect_files": [...],
  "source_hints": { "route": {...} }
}
```

## New API Endpoints

| Method | Path | Controller | Auth |
|--------|------|------------|------|
| `POST` | `/api/v1/source-maps` | `SourceMapController@store` | Sanctum bearer |
| `GET` | `/api/v1/route-maps/lookup` | `RouteMapController@lookup` | Sanctum bearer |
| `POST` | `/api/v1/route-maps` | `RouteMapController@store` | Sanctum bearer |
| `GET` | `/api/v1/bug-report-configs/{project}` | `BugReportProjectConfigController@show` | Sanctum bearer |
| `PUT` | `/api/v1/bug-report-configs/{project}` | `BugReportProjectConfigController@upsert` | Sanctum bearer |

## Modified Existing Files

| File | Change |
|------|--------|
| `BugReportWidgetController` | Accept 6 optional new fields (deploy_commit, deploy_timestamp, route_name, breadcrumbs, failed_responses, livewire_components) |
| `BugReportSignalController` | Same 6 optional fields |
| `BugReportConnector` | Store new fields in payload; dispatch `EnrichBugReportJob` after signal created |
| `DelegateBugReportToAgentAction` | Include resolved_errors, suspect_files, agent_instructions in experiment thesis |
| `BugReportDetailTool` | Return resolved_errors, suspect_files, agent_instructions in MCP response |

## New MCP Tools

| Tool | Description |
|------|-------------|
| `bug_report_resolve_stack` | Manually trigger stack trace resolution for a signal |
| `route_map_lookup` | Look up route → controller/component for a URL+project |
| `source_map_upload` | Upload a source map via MCP (for CI/CD) |

## Data Flow

```
Widget POST /api/public/widget/bug-report
  → BugReportWidgetController (validate + accept new fields)
  → BugReportConnector::poll()
    → IngestSignalAction::execute() → Signal created
    → EnrichBugReportJob::dispatch($signalId)

EnrichBugReportJob (queue: default)
  → ResolveStackTraceAction
    → StackTraceParser::extractFrames(console_log errors)
    → SourceMapResolver::resolve(frame) for each frame
    → Signal.payload['resolved_errors'] updated
  → AnalyzeSuspectFilesAction
    → RouteMap lookup by (team, project, url)
    → Combine: stack frames + route map + network log + livewire_components
    → Rank by confidence
    → Signal.payload['suspect_files'] updated

DelegateBugReportToAgentAction
  → Read payload['resolved_errors'], payload['suspect_files']
  → BugReportProjectConfig lookup (test/lint commands)
  → Build enriched thesis with all data
  → CreateExperimentAction
```

## VLQ Decoding (Source Map Resolution)

Source maps use VLQ base-64 encoding. PHP implementation in `SourceMapResolver`:
1. Split `mappings` string by `;` (lines) and `,` (segments)
2. Decode each segment's VLQ integers (continuation bit + sign bit)
3. Accumulate deltas to get absolute (genLine, genCol) → (srcIdx, origLine, origCol)
4. Binary search for matching (genLine, genCol) given a stack frame
5. Return `sources[srcIdx]` + original line/col

Result is cached in Redis for 30 minutes per source map ID.
