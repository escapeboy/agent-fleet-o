# Design — Assistant UI Artifacts Hardening Sprint

**Date:** 2026-04-11
**Branch:** `feat/assistant-ui-artifacts-hardening`
**Parent feature:** `feat/assistant-ui-artifacts` (merged `d7e06f7`)
**Status:** approved to build

Cleanup sprint for the deferred items from the Gap 2 Assistant UI Artifacts sprint retro. Closes the Phase 8 (security hardening) and Phase 6 (destructive action hardening) items that shipped as minimal MVPs in the main Gap 2 sprint so the feature flag is actually safe to enable.

## Scope

Four bundles, ranked by risk reduction:

### 1. Security test corpora (~2h each)

**a) Fuzz tests for VOs** — for each of the 9 artifact VOs, throw 200+ random/malformed payloads through `fromLlmArray()` and assert:
- Never throws uncaught exception
- Never returns a VO with an unsanitized field (no `<script>`, no raw HTML, no URL with `javascript:`, no tool name without provenance binding)
- Always respects caps (rows ≤ 50, options ≤ 20, etc.)

**b) Prompt injection corpus** — fixture file `tests/Fixtures/prompt_injection_corpus.json` with ≥20 known attack payloads:
- `<script>alert(1)</script>` in every string field
- `javascript:` / `data:` URLs in every URL field
- Directory traversal (`../../../etc/passwd`) in `file_path`
- Very large payloads (> cap)
- Nested type confusion (artifact inside artifact)
- Prompt-injection-style text content ("IGNORE PREVIOUS INSTRUCTIONS...")
- Source tool name forging (claim a tool that wasn't called)

Each corpus entry runs through `ArtifactFactory::build()` and asserts a known-safe output (null OR sanitized VO with verified field values).

### 2. Destructive action hardening (~3h)

Current Phase 6 state: `handleArtifactConfirm()` and `handleArtifactChoice()` just send a follow-up message. Anyone who can see the assistant panel can click a "destructive" choice card and have the LLM re-interpret it. That's sufficient for a flag-off feature but not for opening to paying customers.

**Harden:**
- **Per-role gating** in the Livewire handler: `viewer` → click ignored + flash "insufficient permission", `member` → allowed for non-destructive only, `admin`/`owner` → allowed after confirm modal.
- **Rate limit** — 10 artifact clicks per user per minute, enforced via `RateLimiter::attempt()`. Over limit → flash + no-op.
- **Audit row** per click: `AuditEntry('assistant.artifact_action', ...)` with artifact type, tool name (if any), role, outcome.
- **Explicit destructive gate** — if the action claims `destructive: true`, the handler ALSO requires role >= admin + an extra confirmation via Livewire's `wire:confirm` (already in the blade, but the handler must also refuse to proceed if the modal was bypassed).

### 3. Pop-out modal + "Show all N" (~2h)

Failure mode from the ui-ux-guardian review: wide content in a 420px panel gets clipped. Mitigations:

- **Pop-out icon button** in the collapsible `<summary>` bar that opens the artifact in a centered full-screen modal. Same Blade component renders the body at larger dimensions.
- **`data_table` "Show all N" link** in the footer when rows were truncated (row count > 10 OR `payload.truncated === true`). Click opens the same modal, body shows all rows instead of the first 10.
- Modal is a simple Alpine `x-data` overlay with `x-trap` focus trap + Esc-to-close + click-outside-to-close. Reuses the existing design tokens.

### 4. Mobile media query force-collapse (~30m)

Below 360px panel width, override the "first artifact pre-opened" rule so every artifact starts collapsed. Tiny CSS media query in the panel view, no PHP changes.

## Out of scope (v1.5 backlog)

- **Haiku intent-check optimization** — defer until we have real profiling data showing the extended prompt is hurting token budget
- **CSP audit** — needs manual review of current headers with a non-author reviewer
- **Chart.js integration** — defer until the SVG-free renderer is proven insufficient
- **Real chart library for pie/line** — same reasoning
- **Mobile-specific rendering for tables** — wait for feedback from actual mobile users

## Test plan

- **Unit tests:** 9 fuzz test cases (one per VO type), each runs 200 random payloads.
- **Corpus test:** `ArtifactPromptInjectionCorpusTest` iterates the fixture file and asserts safe handling of every entry.
- **Livewire tests:** extend `AssistantPanelArtifactHandlersTest` with role-gating cases (viewer blocked, member blocked on destructive, admin confirmed), rate limit cases (11th click within a minute blocked), audit entry creation.
- **Feature flag interaction:** confirm the hardening path is reached only when the feature flag is on; when off, all handlers short-circuit unchanged.

## Deploy plan

- Merge to base develop + main, bump parent submodule, push.
- Deploy to fleetq.net via the usual `merge origin/develop` path.
- Feature remains dormant (global kill switch OFF + per-team toggle OFF) until user explicitly enables.
- Post-deploy smoke: rerun the 5-step verification.

## References

- `docs/design-assistant-ui-artifacts.md` (Gap 2 main sprint)
- `claudedocs/security-checklist-assistant-ui-artifacts-2026-04-11.md` (§2.11 testing matrix, §2.6 action safety)
- `claudedocs/research_ephemeral_ui_2026-04-11.md`
- Base commit `d7e06f7` (feat/assistant-ui-artifacts merge tip)
