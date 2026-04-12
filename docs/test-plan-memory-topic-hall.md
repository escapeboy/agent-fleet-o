# Test Plan: Memory Topic Namespace + Hall Taxonomy + Auto-Save

**Date:** 2026-04-12
**Architecture doc:** `docs/architecture-memory-topic-hall.md`

## Acceptance Criteria

1. `memories.topic` column exists; nullable; indexed with agent_id+category composite
2. `MemoryCategory` has 4 new values: facts, events, discoveries, advice
3. `StoreMemoryAction` accepts `topic` param; dispatches `ClassifyMemoryTopicJob` when topic is null
4. `RetrieveRelevantMemoriesAction` pre-filters by topic when provided
5. `MemorySearchTool`, `MemoryUnifiedSearchTool`, `MemoryAddTool` accept `topic` param
6. `AutoSaveConversationMemoryJob` stores memories from conversation on milestone (every 15 messages)
7. `memory:classify-topics` artisan command exists and classifies null-topic memories

## Unit Tests

### MemoryCategory enum
```php
it('has new canonical hall values', function () {
    expect(MemoryCategory::Facts->value)->toBe('facts');
    expect(MemoryCategory::Events->value)->toBe('events');
    expect(MemoryCategory::Discoveries->value)->toBe('discoveries');
    expect(MemoryCategory::Advice->value)->toBe('advice');
});

it('retains existing values', function () {
    expect(MemoryCategory::Preference->value)->toBe('preference');
    expect(MemoryCategory::Knowledge->value)->toBe('knowledge');
    expect(MemoryCategory::Context->value)->toBe('context');
    expect(MemoryCategory::Behavior->value)->toBe('behavior');
    expect(MemoryCategory::Goal->value)->toBe('goal');
});
```

### RetrieveRelevantMemoriesAction — topic pre-filter
```php
it('filters by topic when provided', function () {
    $team = Team::factory()->create();
    $agent = Agent::factory()->for($team)->create();

    Memory::factory()->for($team)->create([
        'agent_id' => $agent->id,
        'topic' => 'auth_migration',
        'content' => 'We decided to use JWT tokens',
        'embedding' => fake_embedding(),
    ]);
    Memory::factory()->for($team)->create([
        'agent_id' => $agent->id,
        'topic' => 'checkout_flow',
        'content' => 'Stripe is the payment provider',
        'embedding' => fake_embedding(),
    ]);

    $results = app(RetrieveRelevantMemoriesAction::class)->execute(
        agentId: $agent->id,
        query: 'authentication',
        topic: 'auth_migration',
        teamId: $team->id,
        scope: 'agent',
    );

    expect($results)->toHaveCount(1);
    expect($results->first()->topic)->toBe('auth_migration');
});

it('returns all matching memories when topic is null', function () {
    // topic=null → no pre-filter → same as before
    $results = app(RetrieveRelevantMemoriesAction::class)->execute(
        agentId: $agent->id,
        query: 'authentication',
        topic: null,
        teamId: $team->id,
        scope: 'agent',
    );
    expect($results->count())->toBeGreaterThanOrEqual(2);
});
```

### StoreMemoryAction — topic dispatch
```php
it('dispatches ClassifyMemoryTopicJob when topic is null', function () {
    Queue::fake();

    $team = Team::factory()->create();
    app(StoreMemoryAction::class)->execute(
        teamId: $team->id,
        agentId: null,
        content: 'We decided to use Redis for session storage',
        sourceType: 'manual',
        topic: null,
    );

    Queue::assertPushed(ClassifyMemoryTopicJob::class);
});

it('does not dispatch job when topic is provided', function () {
    Queue::fake();

    app(StoreMemoryAction::class)->execute(
        teamId: $team->id,
        agentId: null,
        content: 'We decided to use Redis for session storage',
        sourceType: 'manual',
        topic: 'session_storage',
    );

    Queue::assertNotPushed(ClassifyMemoryTopicJob::class);
});

it('persists topic when provided', function () {
    $memories = app(StoreMemoryAction::class)->execute(
        teamId: $team->id,
        agentId: null,
        content: 'We decided to use Redis for session storage',
        sourceType: 'manual',
        topic: 'session_storage',
    );

    expect($memories[0]->topic)->toBe('session_storage');
});
```

### AutoSaveConversationMemoryJob
```php
it('stores memories from conversation on dispatch', function () {
    $team = Team::factory()->create();
    $user = User::factory()->for($team)->create();
    $conversation = AssistantConversation::factory()->for($team)->create();

    // Create 15 messages
    AssistantMessage::factory()->count(15)->for($conversation)->create([
        'role' => fn ($i) => $i % 2 === 0 ? 'user' : 'assistant',
    ]);

    // Fake Haiku response
    $this->mockGateway->shouldReturnJson([
        ['content' => 'Team uses JWT tokens', 'category' => 'facts', 'topic' => 'auth', 'importance' => 0.8],
    ]);

    dispatch(new AutoSaveConversationMemoryJob($conversation->id, $team->id, $user->id));

    expect(Memory::where('source_type', 'assistant_conversation')
        ->where('source_id', $conversation->id)
        ->count()
    )->toBeGreaterThanOrEqual(1);
});

it('fails silently when LLM returns invalid JSON', function () {
    $this->mockGateway->shouldReturnRaw('not json');

    // Should not throw
    expect(fn () => dispatch(new AutoSaveConversationMemoryJob($conversationId, $teamId, $userId)))
        ->not->toThrow(Throwable::class);
});
```

### SendAssistantMessageAction — auto-save trigger
```php
it('dispatches AutoSaveConversationMemoryJob on 15th message', function () {
    Queue::fake();

    $conversation = AssistantConversation::factory()->create();
    // Pre-seed 14 messages
    AssistantMessage::factory()->count(14)->for($conversation)->create();

    // Send 15th message
    app(SendAssistantMessageAction::class)->execute($conversation, 'hello', $user);

    Queue::assertPushed(AutoSaveConversationMemoryJob::class);
});

it('does not dispatch on non-milestone message count', function () {
    Queue::fake();

    // Pre-seed 13 messages → total will be 14 after this call
    AssistantMessage::factory()->count(13)->for($conversation)->create();
    app(SendAssistantMessageAction::class)->execute($conversation, 'hello', $user);

    Queue::assertNotPushed(AutoSaveConversationMemoryJob::class);
});
```

## Integration Tests

### MCP tool: memory_search with topic
```php
it('memory_search MCP tool filters by topic', function () {
    // Store two memories with different topics
    // Call memory_search with topic='auth_migration'
    // Assert only auth_migration memory returned
});
```

### MCP tool: memory_add with topic
```php
it('memory_add MCP tool stores topic', function () {
    // Call memory_add with topic='checkout_flow'
    // Assert memory stored with correct topic
    // Assert no ClassifyMemoryTopicJob dispatched
});
```

### Artisan command: memory:classify-topics
```php
it('classifies null-topic memories', function () {
    Memory::factory()->count(3)->create(['topic' => null]);

    $this->artisan('memory:classify-topics', ['--limit' => 3])
        ->assertSuccessful();
});

it('dry-run does not update database', function () {
    Memory::factory()->count(2)->create(['topic' => null]);

    $this->artisan('memory:classify-topics', ['--dry-run' => true]);

    expect(Memory::whereNotNull('topic')->count())->toBe(0);
});
```

## Edge Cases

- Memory content is empty string → `StoreMemoryAction` returns early (existing behaviour), no job dispatched
- `ClassifyMemoryTopicJob` fails (LLM error) → topic stays null, memory still exists
- `AutoSaveConversationMemoryJob`: conversation has < 15 messages → job still runs (e.g. manually dispatched), extracts what it can
- `AutoSaveConversationMemoryJob`: duplicate prevention — `content_hash` dedup in `StoreMemoryAction` prevents storing same facts twice if job runs twice for same window
- Topic slug sanitization: LLM might return spaces or camelCase — trim + lowercase + replace spaces with underscores in `ClassifyMemoryTopicAction`
- `MemoryCategory::tryFrom('unknown_value')` → null → handled gracefully in all tools (existing pattern)

## Regression Checks

After implementation, verify these existing behaviours are unchanged:
- `RetrieveRelevantMemoriesAction` with `topic=null` returns same results as before migration
- Existing MCP tools (`memory_search` without topic param) still work
- `MemoryCategory::tryFrom('preference')` still resolves correctly
- `StoreMemoryAction` without `topic` arg still stores successfully (defaults to null)
