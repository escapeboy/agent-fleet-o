# Design — Assistant Panel UI Artifacts (Gap 2 / Ephemeral UI)

**Date:** 2026-04-11
**Feature branch:** `feat/assistant-ui-artifacts`
**Status:** approved to build (user-answered open questions from `claudedocs/security-checklist-assistant-ui-artifacts-2026-04-11.md` §5)
**Owner:** katsarov
**Depends on:** `feat/smart-clarification-forms` (merged to base develop@88f4e4d) — reuses `sanitizeFormSchema()` pattern for the `form` artifact type
**Precondition:** full security checklist walked through before merge

---

## Approved answers to Gap 2 open questions

From the user on 2026-04-11:

1. **Model selection:** two-model flow. **Haiku 4.5** does the "would artifacts help this answer?" intent-check. **Sonnet 4.6** (main assistant model) generates artifact JSON when Haiku says yes. Two calls per turn in the artifact-generating path, one call in the plain-text path.
2. **Artifact types:** **all 9** from the checklist scope — `data_table`, `chart`, `choice_cards`, `form`, `link_list`, `code_diff`, `confirmation_dialog`, `metric_card`, `progress_tracker`.
3. **Interactivity:** **frozen** at generation time. No client-side filtering, sorting, or re-rendering. A click on a choice card triggers a new assistant turn; it does not mutate the existing artifact.
4. **Layout when text + N artifacts:** consulted ui-ux-guardian agent (decision captured in §4 below once it returns).
5. **Queryable history:** **yes** — artifacts get their own queryable table + indexes.
6. **Provenance guard:** **strict** — every `data_table` / `chart` MUST carry a verified `source_tool` that actually ran in the same assistant message turn, validated at save time.

---

## Phase plan

~5-6 days compressed across multi-session work. Each phase ships as its own commit on `feat/assistant-ui-artifacts`. User can bail at any phase boundary.

| Phase | Scope | Deliverables | ~hours |
|---|---|---|---|
| 0 | Design doc + ui-ux consultation | this doc, layout decision | 1 |
| 1 | Data model | migration, `AssistantUiArtifact` Eloquent model, JSON column on `assistant_messages`, FK table for queryability, indexes | 2 |
| 2 | VO hierarchy | abstract base + 9 subclasses + sanitizers + unit tests per type | 6 |
| 3 | URL validator | `UrlValidator::isSafe()` + fuzz tests (reused across artifact types) | 1 |
| 4 | Renderers | 9 Blade components + wrapper (collapsible/tabs/stack depending on §4) + `sr-only` alt text + keyboard navigation | 6 |
| 5 | Tool loop extension | Haiku intent-check + Sonnet system prompt extension + response parser + provenance validator | 4 |
| 6 | Action handler | click → confirm modal → audit → rate-limit → tool invocation (for `choice_cards` + `confirmation_dialog`) | 3 |
| 7 | Feature flag + admin UI | `teams.assistant_ui_artifacts_allowed` column, super-admin dashboard toggle, global kill switch in `GlobalSetting` | 2 |
| 8 | Security tests | fuzz corpus, prompt-injection corpus, cross-tenant isolation test, CSP review | 3 |
| 9 | Integration + deploy | merge → prod deploy → canary 48h → open to whitelisted teams | 2 |

**Total:** ~30 hours = ~4 working days compressed.

---

## 1. Data model

### 1.1 Migration — add artifact storage

```php
Schema::table('assistant_messages', function (Blueprint $table) {
    $table->jsonb('ui_artifacts')->nullable()->comment('Frozen snapshot of artifacts for fast render');
});

Schema::create('assistant_ui_artifacts', function (Blueprint $table) {
    $table->uuid('id')->primary();
    $table->foreignUuid('team_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('assistant_message_id')->constrained()->cascadeOnDelete();
    $table->foreignUuid('conversation_id')->constrained('assistant_conversations')->cascadeOnDelete();
    $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
    $table->string('type', 32);            // data_table, chart, ...
    $table->integer('schema_version')->default(1);
    $table->jsonb('payload');              // sanitized, validated, ready-to-render
    $table->string('source_tool', 64)->nullable(); // for provenance guard
    $table->integer('size_bytes');
    $table->timestamp('rendered_at')->nullable();  // set on first server-side render
    $table->timestamp('created_at');
    $table->index(['team_id', 'created_at']);
    $table->index(['type']);
    $table->index(['assistant_message_id']);
    $table->index('payload', 'assistant_ui_artifacts_payload_gin_idx', 'gin');
});
```

**Denormalization rationale:** we keep `ui_artifacts` as JSONB on the message row for fast rendering (one SELECT fetches text + artifacts together), AND also materialize each artifact as a row in `assistant_ui_artifacts` for queryability. The two MUST be kept in sync by a single atomic write inside `SendAssistantMessageAction::saveResponse()`.

### 1.2 Model

```php
class AssistantUiArtifact extends Model
{
    use BelongsToTeam, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'team_id', 'assistant_message_id', 'conversation_id', 'user_id',
        'type', 'schema_version', 'payload', 'source_tool', 'size_bytes',
        'rendered_at', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'created_at' => 'datetime',
            'rendered_at' => 'datetime',
        ];
    }
}
```

### 1.3 Eloquent relation on `AssistantMessage`

```php
public function uiArtifacts(): HasMany
{
    return $this->hasMany(AssistantUiArtifact::class, 'assistant_message_id');
}
```

---

## 2. Value Object hierarchy

```
AssistantUiArtifact (abstract, final per subclass)
├─ DataTableArtifact        — cols, rows, source_tool (REQUIRED)
├─ ChartArtifact            — chart_type, data_points, source_tool (REQUIRED)
├─ ChoiceCardsArtifact      — options[] with actions
├─ FormArtifact             — reuses Gap 1 sanitizer
├─ LinkListArtifact         — items[] with url + label
├─ CodeDiffArtifact         — language, before, after, line_count
├─ ConfirmationDialogArt.   — title, body, confirm_label, destructive, action
├─ MetricCardArtifact       — label, value, unit?, delta?
└─ ProgressTrackerArtifact  — label, progress (0-100), eta?
```

Each subclass implements:

```php
final readonly class DataTableArtifact extends AssistantUiArtifact
{
    public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?self
    {
        // 1. Validate shape
        // 2. Enforce caps (max 8 columns, 50 rows, 200 char cells)
        // 3. Sanitize every string via strip_tags + substr
        // 4. Validate source_tool exists and actually ran in this turn
        // 5. Return null on any failure (caller logs + drops)
    }

    public function toPayload(): array { /* JSON-serializable */ }

    public function render(): View { /* Blade component */ }
}
```

### Per-type caps (extending §2.2 of security checklist)

| Type | Key caps |
|---|---|
| DataTable | 8 cols, 50 rows, 200 chars/cell, source_tool required |
| Chart | 100 data points, chart_type ∈ {line, bar, pie, area}, source_tool required |
| ChoiceCards | 6 options, 100 chars/label, action whitelist |
| Form | reuse `DetectClarificationNeeded::sanitizeFormSchema()` — 6 fields, 20 options, 9 whitelisted types |
| LinkList | 10 items, 200 chars/label, URL whitelist |
| CodeDiff | max 2 diffs per msg, 100 lines per diff, 5000 chars total, language ∈ {php, ts, js, py, rb, go, rust, yaml, json, md, sql} |
| ConfirmationDialog | title ≤ 100, body ≤ 500, 2 buttons max, destructive=boolean |
| MetricCard | label ≤ 50, unit ≤ 20, value ∈ numeric, delta ∈ numeric, trend ∈ {up, down, neutral} |
| ProgressTracker | label ≤ 100, progress ∈ 0-100 int, eta ≤ 50 chars |

**Global caps:** max 3 artifacts per message, max 32KB total serialized payload per message.

---

## 3. Tool loop extension — the two-model flow

### 3.1 Happy path (text-only answer, no artifacts)

```
user message ──▶ Sonnet 4.6 ──▶ text reply ──▶ saved
```
Unchanged from today. No extra LLM call.

### 3.2 Artifact-eligible path

```
user message
    │
    ▼
Haiku 4.5 intent check (~200 tokens, ~150ms)
    │ "would a table/chart/form help answer this?"
    ├─ no  ──▶ Sonnet 4.6 with standard prompt ──▶ text reply ──▶ saved
    │
    └─ yes ──▶ Sonnet 4.6 with EXTENDED prompt (teaches artifact format)
                │  +includes examples of each allowed type
                │  +runs tool calls as usual
                │
                ▼
            parsed response
                │
                ▼
            For each detected artifact:
              - fromLlmArray() sanitize
              - verify source_tool actually ran this turn
              - if both pass → keep artifact
              - if either fails → drop + log suspicious_artifact
                │
                ▼
            saveResponse() — single DB transaction:
              1. insert AssistantMessage with ui_artifacts JSON
              2. insert 1 row per artifact into assistant_ui_artifacts
              3. attach audit trail
```

### 3.3 Haiku intent-check prompt

```
You decide whether an assistant reply would benefit from structured UI elements
("artifacts") or plain text is best.

Return ONLY JSON: {"artifacts_helpful": true|false, "likely_types": [...]}

Artifacts help when the answer involves:
- Listing 3+ items with attributes (use data_table)
- Time-series or distribution (use chart)
- Choosing 1 from 2-6 options (use choice_cards)
- Completing a quick input (use form)
- Referring to 3+ external URLs (use link_list)
- Showing code changes (use code_diff)
- Confirming a destructive operation (use confirmation_dialog)
- A single headline metric (use metric_card)
- Tracking a running operation (use progress_tracker)

Plain text is best when: the user asks a conceptual question, a yes/no, a
single-sentence summary, or any answer that would be worse as a UI widget.
```

### 3.4 Sonnet extended prompt (addendum to existing assistant system prompt)

Append when Haiku said yes:

```
You may return UI artifacts alongside your text response. Format:

{
  "text": "<your normal answer>",
  "artifacts": [
    {"type": "data_table", "source_tool": "experiment_list", "columns": [...], "rows": [...]},
    ...
  ]
}

Rules:
- Max 3 artifacts per reply.
- data_table and chart REQUIRE source_tool — the MCP tool whose output you're
  showing. That tool MUST have been called in THIS turn; do not fabricate data.
- Keep text clear even without the artifacts — the artifact is a bonus, not a
  replacement for a good textual answer.
- If a form or confirmation_dialog would be better served by just asking the
  user directly in text, do that instead.
- You only ever render the 9 whitelisted types. Do not invent new ones.
```

### 3.5 Parser

Response parser lives in `base/app/Domain/Assistant/Services/AssistantUiArtifactParser.php`. Signature:

```php
public function parse(string $assistantResponseJson, array $toolCallsInTurn): array
{
    // Returns: ['text' => string, 'artifacts' => AssistantUiArtifact[]]
    // Drops anything malformed. Never throws.
}
```

---

## 4. Layout strategy (pending ui-ux-guardian)

Status: **pending** — agent consultation launched at Phase 0.

When the agent returns, the decision goes here. Until then, the implementation will sketch for Option D (collapsible cards, first open) as the default because it's the user's preferred option and maps cleanly to `<details>` semantics with free keyboard + screen reader support.

Once ui-ux-guardian decides, this section becomes concrete and the Phase 4 Blade renderers follow its mapping.

---

## 5. Feature flag + rollout

Following the Claude Code VPS pattern:

1. **Migration:** `teams.assistant_ui_artifacts_allowed boolean default false`
2. **Global kill switch:** `GlobalSetting::get('assistant.ui_artifacts_enabled', false)` — checked inside `SendAssistantMessageAction` before the Haiku intent-check even runs. Bypasses everything if false.
3. **Super-admin toggle:** new column in `cloud/resources/views/livewire/admin/super-admin-dashboard.blade.php` with the same pattern as "Claude VPS" column. Controlled by `toggleAssistantUiArtifacts($teamId)` action on `SuperAdminDashboard.php`.
4. **Canary procedure:**
   - Day 0: enable for 1 internal team (katsarov's own)
   - Day 0-2: monitor `suspicious_artifact` audit events, per-team artifact count, sanitization failure rate
   - Day 2+: if clean, open to whitelisted teams per super-admin discretion
5. **Kill criteria:** if any `suspicious_artifact` event count exceeds threshold, or if CSP violation reports come in, or if a prompt-injection corpus test starts failing — flip global kill switch OFF, investigate, fix, re-enable.

---

## 6. Provenance guard — the most critical invariant

> Every `data_table` / `chart` artifact MUST carry a `source_tool` field. At save time, we verify that `source_tool` matches a tool call that ACTUALLY ran in the same assistant turn.

### Implementation

In `AssistantUiArtifactParser::parse()`:

```php
public function parse(string $llmJson, array $toolCallsInTurn): array
{
    $toolNames = array_column($toolCallsInTurn, 'name');
    $raw = json_decode($llmJson, true) ?? [];

    $artifacts = [];
    foreach ($raw['artifacts'] ?? [] as $rawArtifact) {
        $type = $rawArtifact['type'] ?? null;

        // Strict types that require provenance binding
        if (in_array($type, ['data_table', 'chart'], true)) {
            $sourceTool = $rawArtifact['source_tool'] ?? null;
            if (! $sourceTool || ! in_array($sourceTool, $toolNames, true)) {
                Log::warning('Artifact provenance violation — dropped', [
                    'type' => $type,
                    'claimed_source' => $sourceTool,
                    'available_tools' => $toolNames,
                ]);
                // write suspicious_artifact audit entry
                continue;
            }
        }

        $vo = AssistantUiArtifact::fromLlmArray($rawArtifact, $toolCallsInTurn);
        if ($vo !== null) {
            $artifacts[] = $vo;
        }
    }

    return ['text' => $raw['text'] ?? '', 'artifacts' => $artifacts];
}
```

This blocks the LLM from fabricating data like "here's a table of the top 5 campaigns" when no campaign-listing tool was called. The LLM can still write text about them (text is subject to normal hallucination risk), but it cannot produce a *rendered UI artifact* that looks authoritative when it has no data source.

### Per-type policy

- **Strict (source_tool required):** `data_table`, `chart`
- **Loose (no source_tool needed):** `choice_cards`, `form`, `link_list`, `code_diff`, `confirmation_dialog`, `metric_card`, `progress_tracker`
- **Metric_card exception:** accepts either a `source_tool` OR a literal number; if a tool ran, prefer the tool value. Numbers without tools are allowed only if the user is just asking for a manual calculation (e.g. "what's 20% of 500?").

---

## 7. Test matrix

Per-type unit tests + cross-cutting feature tests + security corpus.

### 7.1 Unit (per VO subclass)

For each of the 9 types:
- Happy path (valid LLM payload → valid VO)
- XSS in strings (script tags in labels) → stripped
- Unknown type on cap-enforced fields → dropped
- Caps exceeded (rows, columns, options) → truncated or dropped
- Malformed nesting → null
- Empty options → degraded to safer fallback or dropped
- Missing source_tool on strict types → null

### 7.2 Feature (full round-trip)

- Message with text only → no artifacts stored
- Message with text + 1 artifact → 1 row in `assistant_ui_artifacts` + JSON on message row
- Message with text + 3 artifacts → 3 rows + JSON match
- Message with text + 4 artifacts (cap exceeded) → 3 kept, 1 dropped, audit entry
- Feature flag off → no Haiku call, no artifacts, standard text flow
- Team not whitelisted → same as above
- Global kill switch flipped mid-conversation → next message is text-only
- Sonnet returns invalid JSON → text-only, log, no crash

### 7.3 Prompt injection corpus

Fixture file: `base/tests/Fixtures/prompt_injection_corpus.json` with 20+ known attack patterns. Every test run iterates through the corpus and asserts nothing unsafe leaks.

### 7.4 Cross-tenant isolation

- Team A user sees team A artifacts only
- Team A user who somehow crafts a request referencing team B's `conversation_id` → 403
- Artifact payload validates `team_id == current_team_id` at render time (belt + braces)

---

## 8. Out of scope for v1

Explicit non-goals so this doesn't grow into a quarter-long project:

- **Client-side interactivity** (sort/filter data_table without new LLM call) — deferred to v2
- **Streaming partial artifacts** (artifact rendering mid-stream) — deferred; v1 renders only after the full response lands
- **Custom artifact types** (user-defined) — deferred indefinitely, security too hard
- **PDF/PNG export** of rendered artifacts — deferred
- **Real-time collaborative artifact editing** — not in scope ever
- **A chart library beyond the existing one** (if any)
- **Mobile-specific layout** — v1 uses whatever the Phase 4 layout decision is, uniform across breakpoints
- **Artifact "pinning"** (keep visible after conversation scrolls past) — deferred

---

## 9. Open questions for v1.5 / v2

Parking lot:

1. Should `code_diff` artifacts support syntax highlighting via PrismJS / Shiki? (Would need a CSP-safe local bundle.)
2. Should `chart` artifacts use Chart.js, Frappe Charts, or plain SVG? (Implications on CSP + bundle size + CDN policy.)
3. Should we let users "save" an artifact (pin it to their dashboard)?
4. Telemetry: when user clicks a `link_list` item, do we also track which artifact type → click → destination for future ranking?

Answers to these will shape v1.5.

---

## 10. References

- Research doc: `claudedocs/research_ephemeral_ui_2026-04-11.md`
- Security checklist: `claudedocs/security-checklist-assistant-ui-artifacts-2026-04-11.md`
- Gap 1 sanitizer (reused for `form` artifact): `base/app/Domain/Agent/Pipeline/Middleware/DetectClarificationNeeded.php::sanitizeFormSchema()`
- Similar feature-flag + per-team-toggle pattern: `cloud/Livewire/Admin/SuperAdminDashboard.php::toggleClaudeCodeVps()`
- Existing assistant service: `base/app/Domain/Assistant/Actions/SendAssistantMessageAction.php`
- Assistant message model: `base/app/Domain/Assistant/Models/AssistantMessage.php`
