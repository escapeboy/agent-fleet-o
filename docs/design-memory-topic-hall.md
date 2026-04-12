# Design: Memory Topic Namespace + Hall Taxonomy + Auto-Save

**Date:** 2026-04-12
**Source:** MemPalace research (claudedocs/research_mempalace_2026-04-12.md)

## Problem

FleetQ's memory retrieval does a flat pgvector cosine scan across all team memories. At scale (500+ memories per agent) this degrades precision — irrelevant memories from other contexts score above threshold and pollute retrieval. MemPalace benchmarks +34% R@10 improvement by pre-filtering on namespace (Wing + Hall + Room) before vector search.

Additionally, the current `MemoryCategory` enum (preference/knowledge/context/behavior/goal) lacks canonical semantics — agents and the assistant store memories inconsistently because categories don't map to "what kind of information is this."

Finally, the AssistantPanel has no memory auto-save — conversation context is lost between sessions unless a user or agent explicitly calls `memory_add`.

## Solution

Three additive, backwards-compatible changes:

### 1. Topic column (namespace pre-filter)
Add `topic` varchar(255) nullable to `memories`. A topic is a named context slice, e.g. `"auth-migration"`, `"checkout-flow"`, `"database-design"`. When a query passes `topic`, retrieval pre-filters to matching memories before pgvector — reducing candidate set and improving precision.

Existing memories: backfill via LLM (Haiku) classification.
New memories: auto-classified on store (async job).

### 2. Hall taxonomy (canonical memory categories)
Extend `MemoryCategory` with 4 new canonical values from MemPalace's hall system:
- `facts` — decisions made, locked-in choices
- `events` — sessions, milestones, debugging logs
- `discoveries` — breakthroughs, new insights
- `advice` — recommendations and solutions

Existing values (preference/knowledge/context/behavior/goal) remain valid — no data migration needed. Auto-classification on store assigns the best-fit category when none is provided.

### 3. Auto-save triggers
After every completed assistant message, if `conversation.messages().count() % 15 == 0`, dispatch `AutoSaveConversationMemoryJob`. The job extracts key facts/decisions from the last 15 messages using Haiku and stores them as typed memories with topics.

## User Stories

1. **Agent at scale**: "My agent has 2000 memories. When it's debugging the auth system, retrieval should surface auth-related memories — not random snippets from database migrations or UI tasks."
2. **Assistant user**: "I want the assistant to remember key decisions from our sessions without me having to say 'remember this.'"
3. **Cross-session recall**: "When I come back next week, the assistant should know we decided to use Postgres for the credential store."

## Scope

**In:** migration, backfill command, enum extension, store action update, retrieval action update, MCP tool updates (memory_search, memory_unified_search, memory_add), auto-save job, trigger in SendAssistantMessageAction.

**Out:** Cross-agent tunnels (KG feature, separate sprint). LLM rerank step. AAAK dialect.

## Constraints

- Auto-classification uses Haiku only (cheap). Never Sonnet/Opus for this.
- Topic backfill is opt-in via artisan command, not automatic migration — avoid blocking deploys.
- All changes are additive (no renames, no drops).
- Topic = null → pre-filter skipped → same behaviour as today.
