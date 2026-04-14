# Test Plan: Agent-Optimized Bug Report Processing

## Test Files

- `tests/Feature/Domain/Signal/SourceMapResolutionTest.php`
- `tests/Feature/Domain/Signal/SuspectFilesTest.php`
- `tests/Feature/Domain/Signal/BugReportProjectConfigTest.php`
- Update: `tests/Feature/Domain/Signal/BugReportWidgetTest.php`

## SourceMapResolutionTest

### StackTraceParser
- parses `at functionName (file.js:10:20)` → {file, line, col, function}
- parses `at file.js:10:20` (no function name)
- parses http URL frames: `at fn (https://app.com/widget.js:1:99)`
- skips `node_modules/` frames when filtering project code
- returns empty array for non-error console entries

### SourceMapResolver
- decodes VLQ base64 correctly for known input
- resolves minified (file.js:1:99) → original (src/Component.vue:45:3)
- returns null when no source map found for project+release
- returns null when frame column not in mappings
- isProjectCode false for node_modules paths

### ResolveStackTraceAction
- reads console_log errors from signal payload
- writes `resolved_errors` back to signal.payload
- skips signals with no console_log errors
- handles malformed console_log gracefully (no crash)

### SourceMapController
- POST /api/v1/source-maps stores map for team (201)
- rejects oversized map_data (422)
- upserts existing project+release combination
- requires Sanctum auth (401 without token)

## SuspectFilesTest

### SuspectFilesAnalyzer
- adds firstProjectFrame with confidence 0.95
- adds other project frames with confidence 0.7
- adds route controller with confidence 0.85 when route map matched
- adds livewire_component with confidence 0.85 when route map matched
- adds blade view file with confidence 0.6 based on controller convention
- deduplicates same path keeping highest confidence
- sorts results by confidence descending

### AnalyzeSuspectFilesAction
- writes `suspect_files` to signal.payload
- includes `source_hints.route` when route map matched
- handles signal with no resolved_errors (route-only analysis)

### RouteMapController
- POST /api/v1/route-maps stores routes (201)
- GET /api/v1/route-maps/lookup?project=&url= returns matching route (200)
- GET returns 404 when no route matches
- upserts on same project

### EnrichBugReportJob
- dispatched when BugReportConnector processes signal
- runs ResolveStackTraceAction then AnalyzeSuspectFilesAction
- is idempotent (safe to re-run)

## BugReportProjectConfigTest

- PUT /api/v1/bug-report-configs/{project} creates config (200)
- PUT updates existing config
- GET /api/v1/bug-report-configs/{project} returns config (200)
- GET returns 404 when not configured
- config is scoped to team (another team cannot see it)

## BugReportWidgetTest (updates)

- accepts deploy_commit, deploy_timestamp, route_name as optional fields
- accepts breadcrumbs JSON string as optional (replaces action_log in payload)
- accepts failed_responses JSON string as optional
- accepts livewire_components JSON string as optional
- all new fields are nullable — existing valid payloads still work (backward compat)
- breadcrumbs stored in payload when provided

## DelegateBugReportToAgentAction (integration)

- thesis includes resolved_errors section when available
- thesis includes suspect_files section when available
- thesis includes agent_instructions when project config exists
- thesis includes related_tests mapped from suspect files
- thesis still works when no enrichment data present (backward compat)

## Acceptance Criteria

1. A bug report submitted with a console error containing a minified stack trace gets `resolved_errors` populated asynchronously within the `EnrichBugReportJob`.
2. A project that has uploaded a source map and registered a route map gets `suspect_files` populated for each new bug report.
3. An agent delegated from the bug report page receives an experiment thesis with resolved file paths, suspect files, and test commands — not raw minified stacks.
4. All existing BugReport tests continue to pass (backward compatibility).
5. The `bug_report_detail` MCP tool returns `resolved_errors`, `suspect_files`, and `agent_instructions` when available.
