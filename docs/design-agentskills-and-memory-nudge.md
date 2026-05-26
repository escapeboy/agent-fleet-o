# Design: agentskills.io Skill Interop + Memory Nudge

- **Date:** 2026-05-26
- **Source:** Hermes-agent re-evaluation (`docs/research/research_hermes-agent-reevaluation_2026-05-26.md` in parent repo)
- **Branch:** `feat/agentskills-io-and-memory-nudge` (base)

## Think — why these two

Two borrowable ideas from NousResearch/hermes-agent that FleetQ does **not** already have:

1. **agentskills.io standard compatibility** — Hermes skills are portable in the
   [agentskills.io](https://agentskills.io) open format (a `SKILL.md` folder). FleetQ Skills are
   DB rows with no portable representation. Adding import/export makes our Skills interoperable
   with the entire agentskills.io ecosystem (Claude Code, Cursor, Hermes, etc.) and strengthens
   the Marketplace. **Highest value / lowest effort.**
2. **Memory nudge** — Hermes "nudges itself to persist knowledge" mid-run. FleetQ already
   auto-extracts memories *after* the fact (`DistillTeamEventsAction`, daily) but never reminds
   the agent *in-loop* to capture durable learnings. A small, default-off in-execution nudge.

### Forcing questions
- **Who needs this?** Teams who author/share skills across tools (agentskills.io interop) and
  operators running long-lived agents that should accumulate institutional memory (nudge).
- **Narrowest MVP?** Export one Skill → `SKILL.md`; import a `SKILL.md` → Skill; both via MCP +
  UI. Nudge = one conditional system-prompt section, off by default.
- **"Whoa"?** Drag a skill authored in Claude Code straight into FleetQ (and back) with no
  reformatting; round-trips losslessly via `metadata.fleetq.*`.
- **Compounds?** Every skill becomes portable → Marketplace network effects; nudged agents grow
  their own memory corpus over time without operator babysitting.

## agentskills.io format (authoritative, from specification.md)

A skill is a directory whose only required file is `SKILL.md` = YAML frontmatter + Markdown body.

| Frontmatter field | Required | Constraint |
|---|---|---|
| `name` | Yes | ≤64 chars, `[a-z0-9-]`, no leading/trailing hyphen |
| `description` | Yes | ≤1024 chars, non-empty |
| `license` | No | license name or bundled file ref |
| `compatibility` | No | ≤500 chars, environment requirements |
| `metadata` | No | arbitrary key→value map |
| `allowed-tools` | No | space-separated (experimental) |

Body: Markdown instructions (<5000 tokens recommended). Optional `scripts/`, `references/`,
`assets/` dirs. **FleetQ v1 emits a single `SKILL.md`** (no bundled dirs) — sufficient for LLM
skills, which are our portable subset.

## Architecture

### Feature 1 — Skill ⇄ agentskills.io

```
Skill (DB) ──ExportSkillToAgentSkillsAction──► SKILL.md string
SKILL.md string ──ImportSkillFromAgentSkillsAction──► CreateSkillAction ──► Skill (DB)
```

**`ExportSkillToAgentSkillsAction::execute(Skill $skill): string`**
- `name`  = sanitize(`$skill->slug` ?: `$skill->name`) → lowercase, `[a-z0-9-]`, collapse repeats,
  trim hyphens, truncate 64; fallback `fleetq-skill`.
- `description` = `$skill->description ?: $skill->name`, truncated 1024 (spec requires non-empty).
- `compatibility` = `"FleetQ skill (type: {type})."` (≤500).
- `metadata.fleetq` = `{ type, framework, execution_type, risk_level, status, input_schema,
  output_schema, configuration, current_version }` — enables lossless re-import.
- Body = `$skill->system_prompt` ?: `"# {name}\n\n{description}"` (always non-empty).
- Frontmatter dumped via `Symfony\Component\Yaml\Yaml::dump`.

**`ImportSkillFromAgentSkillsAction::execute(string $teamId, string $skillMd, ?string $createdBy = null): Skill`**
- Split leading `---\n…\n---` frontmatter from body. Throw `InvalidArgumentException` if absent.
- Parse YAML. Require non-empty `name` and `description`; else throw.
- Map back (prefer `metadata.fleetq.*`, else safe defaults):
  - `type` → `SkillType::tryFrom(fleetq.type)` ?: `SkillType::Llm`
  - `executionType` → `ExecutionType::tryFrom(...)` ?: `Sync`
  - `riskLevel` → `RiskLevel::tryFrom(...)` ?: `Low`
  - `inputSchema/outputSchema/configuration` ← `fleetq.*` ?: `[]`
  - `systemPrompt` ← body
- Delegate to `CreateSkillAction` (handles slug, version, embedding job, team scope).

**MCP tools** (registered in `AgentFleetServer`, "Skill" group):
- `skill_export_agentskills` — `#[IsReadOnly] #[IsIdempotent]`; input `skill_id`; returns SKILL.md text.
- `skill_import_agentskills` — `#[IsDestructive]`; input `skill_md`; creates skill, returns id+slug.

**HTTP/UI**:
- `GET /skills/{skill}/export.skill.md` → `SkillExportController` streams `SKILL.md` (text/markdown,
  `Content-Disposition: attachment`). Button on `SkillDetailPage`.
- `GET /skills/import` → `ImportSkillForm` Livewire page: textarea paste → action → redirect to new
  skill. Button on `SkillListPage`.

### Feature 2 — Memory Nudge

**`MemoryNudgeInjector::nudgeFor(Agent $agent): ?string`** — pure function of DB + config:
- Returns `null` unless `team.settings['memory_nudge_enabled'] === true` (default **off**, no migration).
- Returns `null` unless un-memorialized activity ≥ threshold: count `AgentExecution` rows for the
  agent created after the agent's most recent `Memory.created_at` (or all-time if none) ≥
  `config('memory.nudge.execution_threshold')` (default 5).
- Otherwise returns a short instruction string telling the agent to persist durable learnings via
  the memory store tool.

**Wiring**: `AgentPromptCompiler` gains a constructor-injected `MemoryNudgeInjector`. `compile()`
appends a `## Persisting Knowledge` section (single return point) when `nudgeFor` is non-null.
Covers both the template and empty-template (backstory) branches.

**Config** (`config/memory.php`):
```php
'nudge' => [
    'execution_threshold' => env('MEMORY_NUDGE_EXECUTION_THRESHOLD', 5),
],
```

## Non-goals (scope discipline)
- No zip/folder bundling, no `scripts/`/`references/` round-trip (single `SKILL.md` only).
- No new DB columns (nudge uses `team.settings` JSON).
- No REST API endpoints (MCP + UI cover the surface; add later if asked).
- Nudge does **not** auto-write memories — it only reminds; existing distill/consolidate crons own writes.

## Files
New: 2 Actions, 2 MCP tools, `SkillExportController`, `ImportSkillForm` (+view), `MemoryNudgeInjector`.
Edited: `AgentPromptCompiler`, `config/memory.php`, `routes/web.php`, `AgentFleetServer`,
`skill-detail` + `skill-list` Blade views.
