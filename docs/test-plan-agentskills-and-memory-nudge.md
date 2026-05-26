# Test Plan: agentskills.io Interop + Memory Nudge

Test DB: SQLite :memory:, sync queue, array cache. AI faked (no nudge/export touches a real LLM).

## ExportSkillToAgentSkillsAction (unit)
- [ ] Exports an LLM skill → valid frontmatter with required `name` + `description` + body.
- [ ] `name` sanitization: uppercase/spaces/`_`/leading-trailing hyphens → spec-valid `[a-z0-9-]`, ≤64.
- [ ] Empty `description` falls back to skill name (frontmatter `description` never empty).
- [ ] `metadata.fleetq` carries type, framework, execution_type, risk_level, schemas, configuration.
- [ ] Body uses `system_prompt` when present; falls back to `# name + description` when null.
- [ ] `description` >1024 chars is truncated to ≤1024.

## ImportSkillFromAgentSkillsAction (feature, DB)
- [ ] Valid SKILL.md → creates Skill with mapped type/description/system_prompt, team-scoped.
- [ ] `metadata.fleetq.type` honored (e.g. `guardrail`); unknown type falls back to `llm` (no throw).
- [ ] Missing frontmatter delimiters → throws `InvalidArgumentException`.
- [ ] Missing/empty `name` → throws.
- [ ] Missing/empty `description` → throws.
- [ ] Schemas + configuration restored from `metadata.fleetq.*`.

## Round-trip (feature)
- [ ] export(skill) → import() yields a skill with equal type, description, system_prompt, schemas.

## MCP tools (feature)
- [ ] `skill_export_agentskills` returns SKILL.md text for a skill id; not-found id → structured error.
- [ ] `skill_import_agentskills` creates a skill from SKILL.md and returns id + slug.
- [ ] Both tools registered in AgentFleetServer (tool list contains the two names).

## SkillExportController (feature)
- [ ] `GET /skills/{skill}/export.skill.md` returns 200, `text/markdown`, attachment disposition,
      body starts with `---`.

## ImportSkillForm (Livewire feature)
- [ ] Renders at `GET /skills/import` (200).
- [ ] Submitting valid SKILL.md creates a skill and redirects to `skills.show`.
- [ ] Submitting malformed text surfaces a validation error (no skill created).

## MemoryNudgeInjector (unit)
- [ ] Disabled by default (no team setting) → `nudgeFor` returns null.
- [ ] Enabled + executions since last memory < threshold → null.
- [ ] Enabled + executions since last memory ≥ threshold → non-empty nudge string.
- [ ] Enabled + recent memory resets the counter (executions before that memory don't count).

## AgentPromptCompiler nudge wiring (unit)
- [ ] Nudge absent → compiled prompt has no `## Persisting Knowledge` section.
- [ ] Nudge present → section appended (both template and empty-template/backstory branches).

## Regression
- [ ] Existing skill tests unaffected.
- [ ] `vendor/bin/pint` clean; targeted PHPStan on new files clean.
