# Architecture: Memory Topic Namespace + Hall Taxonomy + Auto-Save

**Date:** 2026-04-12
**Design doc:** `docs/design-memory-topic-hall.md`

## Data Flow

```
[StoreMemoryAction]
    ↓ topic=null → dispatch ClassifyMemoryTopicJob (async, Haiku)
    ↓ topic set  → store directly with topic + category

[RetrieveRelevantMemoriesAction]
    ↓ topic!=null → WHERE agent_id + category + topic (namespace pre-filter)
    ↓ then        → pgvector cosine scan on narrowed set
    ↓ composite score (semantic + recency + importance + tier boost)

[SendAssistantMessageAction]
    ↓ after save  → if count % 15 == 0 → dispatch AutoSaveConversationMemoryJob

[AutoSaveConversationMemoryJob]
    ↓ fetch last 15 messages from conversation
    ↓ LLM (Haiku) → extract facts/events/decisions as JSON array
    ↓ for each item → StoreMemoryAction (with classified category + topic)
```

## Files to Create

### Migration
- `database/migrations/2026_04_12_000000_add_topic_to_memories.php`
  - `topic` varchar(255) nullable
  - Index: `memories_topic_namespace_idx (agent_id, category, topic)` — namespace pre-filter
  - Index: `memories_team_topic_idx (team_id, category, topic)` — team-scoped topic search

### Actions
- `app/Domain/Memory/Actions/ClassifyMemoryTopicAction.php`
  - `execute(Memory $memory): void`
  - Calls Haiku with memory content
  - Returns `topic` (snake_case slug, max 50 chars) + optional `category` suggestion
  - Updates `$memory->topic` (and `$memory->category` if currently null)

### Jobs
- `app/Domain/Memory/Jobs/ClassifyMemoryTopicJob.php`
  - `__construct(string $memoryId)`
  - Runs `ClassifyMemoryTopicAction`
  - Queue: `default`

- `app/Domain/Memory/Jobs/AutoSaveConversationMemoryJob.php`
  - `__construct(string $conversationId, string $teamId, string $userId)`
  - Fetches last 15 messages from `assistant_messages` where `role IN (user, assistant)`
  - Builds conversation snippet
  - LLM (Haiku) extracts: `[{content, category, topic, importance}]` (max 5 items)
  - Calls `StoreMemoryAction` for each item with `source_type='assistant_conversation'`, `source_id=$conversationId`
  - Queue: `default`

### Commands
- `app/Console/Commands/ClassifyMemoryTopicsCommand.php`
  - `memory:classify-topics {--limit=500} {--dry-run}`
  - Queries `memories` where `topic IS NULL` + limit
  - Dispatches `ClassifyMemoryTopicJob` per memory (batched)

## Files to Modify

### Enum
- `app/Domain/Memory/Enums/MemoryCategory.php`
  - Add: `Facts = 'facts'`, `Events = 'events'`, `Discoveries = 'discoveries'`, `Advice = 'advice'`
  - Keep: all existing values (preference, knowledge, context, behavior, goal)

### Model
- `app/Domain/Memory/Models/Memory.php`
  - Add `'topic'` to `$fillable`

### Actions
- `app/Domain/Memory/Actions/StoreMemoryAction.php`
  - Add `?string $topic = null` parameter to `execute()`
  - Pass `topic` to `storeChunk()`
  - After storing, if `$topic === null` → `dispatch(new ClassifyMemoryTopicJob($memory->id))`

- `app/Domain/Memory/Actions/StoreMemoryAction::storeChunk()`
  - Accept and persist `$topic`

- `app/Domain/Memory/Actions/RetrieveRelevantMemoriesAction.php`
  - Add `?string $topic = null` to `execute()`
  - Before pgvector scan: `if ($topic !== null) { $builder->where('topic', $topic); }`

- `app/Domain/Memory/Actions/UnifiedMemorySearchAction.php`
  - Add `?string $topic = null` to `execute()`
  - Pass to internal `getVectorResults()` call

### MCP Tools
- `app/Mcp/Tools/Memory/MemorySearchTool.php`
  - Add `'topic'` schema param: `$schema->string()->description('Filter by topic slug, e.g. "auth-migration". Narrows search to a named context before vector scan.')`
  - Pass to `RetrieveRelevantMemoriesAction`

- `app/Mcp/Tools/Memory/MemoryUnifiedSearchTool.php`
  - Add `'topic'` schema param
  - Pass to `UnifiedMemorySearchAction`

- `app/Mcp/Tools/Memory/MemoryAddTool.php`
  - Add `'topic'` schema param: `$schema->string()->description('Named topic context, e.g. "auth-migration". Auto-classified if omitted.')`
  - Pass to `StoreMemoryAction`

### Assistant
- `app/Domain/Assistant/Actions/SendAssistantMessageAction.php`
  - After `$savedMessage` is persisted (both placeholder and new message paths):
    ```php
    $msgCount = $conversation->messages()->count();
    if ($msgCount > 0 && $msgCount % 15 === 0) {
        AutoSaveConversationMemoryJob::dispatch(
            $conversation->id,
            $user->current_team_id,
            $user->id
        )->onQueue('default');
    }
    ```

### Kernel / Schedule
- `app/Console/Kernel.php` or `routes/console.php`
  - Register `ClassifyMemoryTopicsCommand`

## LLM Prompt Design

### ClassifyMemoryTopicAction prompt (Haiku)
```
You are a memory classifier. Given a memory content, return JSON with exactly two fields:
- "topic": a snake_case slug (max 50 chars) naming the context, e.g. "auth_migration", "checkout_flow", "database_design", "user_onboarding". Use null if no clear topic.
- "category": one of facts|events|discoveries|preferences|advice|knowledge|context|behavior|goal|preference. Choose the best fit.

Memory content:
{content}

Return ONLY valid JSON, no explanation.
```

### AutoSaveConversationMemoryJob prompt (Haiku)
```
Extract memorable facts from this assistant conversation. Return a JSON array of up to 5 items, each with:
- "content": the fact/decision/insight to remember (1-2 sentences)
- "category": one of facts|events|discoveries|preferences|advice
- "topic": snake_case slug for the context (e.g. "auth_migration")
- "importance": 0.1–1.0

Only include items worth remembering long-term (decisions, insights, preferences, breakthroughs).
Skip small-talk, greetings, clarifications.

Conversation:
{messages}

Return ONLY valid JSON array.
```

## Index Strategy

```sql
-- Namespace pre-filter (primary use case)
CREATE INDEX memories_topic_namespace_idx ON memories (agent_id, category, topic)
    WHERE topic IS NOT NULL;

-- Team-scoped topic search (assistant panel)
CREATE INDEX memories_team_topic_idx ON memories (team_id, category, topic)
    WHERE topic IS NOT NULL;
```

Both are partial indexes (WHERE topic IS NOT NULL) — no overhead for null-topic rows.

## Backwards Compatibility

- `topic = null` → all pre-filter clauses skipped → identical behaviour to today
- Existing `MemoryCategory` values untouched — no enum rename, no data migration
- `ClassifyMemoryTopicJob` fails silently (try/catch) — store still succeeds without topic
- `AutoSaveConversationMemoryJob` fails silently — conversation still completes normally
