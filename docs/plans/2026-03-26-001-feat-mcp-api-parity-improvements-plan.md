---
title: "feat: MCP/API Parity Improvements — Metrics Tools, Agent Reset, KB Update, KG Fact Delete, Experiment Cost"
type: feat
status: active
date: 2026-03-26
---

# MCP/API Parity Improvements

## Overview

Audit на MCP server и REST API откри 6 категории пропуски:

1. **AgentFleetServer metadata** — версията е `1.1.0` (трябва `1.13.0`), `$instructions` не включва нови домейни, KG инструментите са наредени под `// Signal` коментар
2. **Metrics MCP tools** — `GET /metrics/aggregations` и `GET /metrics/model-comparison` съществуват като REST API, но нямат MCP еквивалент
3. **AgentResetSessionTool** — `POST /agents/{id}/runtime-state/reset-session` съществува в REST API, но липсва MCP инструмент
4. **Knowledge Base update** — REST API не поддържа `PUT /knowledge-bases/{id}`; само create/delete/ingest/search
5. **Experiment cost via REST** — `ExperimentCostTool` съществува за MCP, но `GET /experiments/{id}/cost` липсва в REST API
6. **KG fact delete** — може само да добавяш факти (`KgAddFact`), но не и да ги инвалидираш/изтриваш нито чрез REST, нито чрез MCP

Всичко е в съществуващи домейни с добре установени patterns — без нови DB таблици.

## Problem Statement / Motivation

Agent-native parity: всяко действие, което потребител може да извърши (или REST API клиент), трябва да може да се извърши и от агент чрез MCP. Обратно — богатите MCP инструменти трябва да имат REST еквиваленти за external integrations.

## Technical Approach

### Засегнати файлове

| Файл | Промяна |
|------|---------|
| `app/Mcp/Servers/AgentFleetServer.php` | version, instructions, KG comment, регистрация нови tools |
| `app/Mcp/Tools/System/MetricsAggregationsTool.php` | **нов** |
| `app/Mcp/Tools/System/MetricsModelComparisonTool.php` | **нов** |
| `app/Mcp/Tools/Agent/AgentResetSessionTool.php` | **нов** |
| `app/Mcp/Tools/Signal/KgInvalidateFactTool.php` | **нов** |
| `app/Domain/Knowledge/Actions/UpdateKnowledgeBaseAction.php` | **нов** |
| `app/Domain/KnowledgeGraph/Actions/InvalidateKgFactAction.php` | **нов** |
| `app/Http/Controllers/Api/V1/KnowledgeBaseController.php` | добавяне `update()` метод |
| `app/Http/Controllers/Api/V1/KnowledgeGraphController.php` | добавяне `destroy()` метод |
| `app/Http/Controllers/Api/V1/ExperimentController.php` | добавяне `cost()` метод |
| `routes/api_v1.php` | 3 нови route-а |

---

## Implementation Units

### Unit A — AgentFleetServer quick fixes

**Файл:** `app/Mcp/Servers/AgentFleetServer.php`

**Промени:**
1. `version = '1.1.0'` → `version = '1.13.0'`
2. `$instructions` — обновяване с пълен списък домейни:
   ```
   'FleetQ MCP Server — AI Agent Mission Control Platform. Manage agents, experiments, projects, workflows, crews, skills, tools, credentials, approvals, signals, budgets, memory, knowledge bases, knowledge graph, git repositories, chatbots, email templates/themes, integrations, marketplace, artifacts, webhooks, assistant conversations, bridge connections, evaluations, evolution proposals, and team settings.'
   ```
3. KG tools — преместване от `// Signal (21)` в отделна секция `// KnowledgeGraph (5)` (само коментарите, не позицията в масива — или може и позицията):
   ```php
   // KnowledgeGraph (5)
   KgSearchTool::class,
   KgEntityFactsTool::class,
   KgAddFactTool::class,
   KgGraphSearchTool::class,
   KgEdgeProvenanceTool::class,
   KgInvalidateFactTool::class,  // добавяне на новия инструмент
   ```
4. Регистрация на всички нови tools (вижте Unit B, C, F)

**Execution note:** Прост refactor + additions. Не изисква тест.

---

### Unit B — Metrics MCP Tools (2 нови файла)

**Цел:** MCP агентите да могат да правят същото като `GET /metrics/aggregations` и `GET /metrics/model-comparison`.

**Файлове:**
- `app/Mcp/Tools/System/MetricsAggregationsTool.php`
- `app/Mcp/Tools/System/MetricsModelComparisonTool.php`

**Patterns to follow:**
- `app/Mcp/Tools/System/DashboardKpisTool.php` — структура на read-only System tool
- `app/Http/Controllers/Api/V1/MetricsController.php:aggregations()` и `modelComparison()` — бизнес логиката

**MetricsAggregationsTool:**
```php
protected string $name = 'system_metrics_aggregations';
protected string $description = 'Query aggregated metric summaries per period. Supports filtering by period (hourly/daily/weekly/monthly), metric_type, experiment_id, and date range.';

public function schema(JsonSchema $schema): array
{
    return [
        'period'        => $schema->string()->description('hourly|daily|weekly|monthly')->nullable(),
        'metric_type'   => $schema->string()->description('Filter by metric type key')->nullable(),
        'experiment_id' => $schema->string()->description('Filter by experiment UUID')->nullable(),
        'from'          => $schema->string()->description('ISO date string (inclusive)')->nullable(),
        'to'            => $schema->string()->description('ISO date string (inclusive)')->nullable(),
        'limit'         => $schema->integer()->description('Max results, default 100, max 500')->nullable(),
    ];
}
```

**MetricsModelComparisonTool:**
```php
protected string $name = 'system_metrics_model_comparison';
protected string $description = 'Compare LLM provider/model usage: cost, latency, token counts. Optionally filter by date range.';

public function schema(JsonSchema $schema): array
{
    return [
        'from' => $schema->string()->description('ISO date (inclusive)')->nullable(),
        'to'   => $schema->string()->description('ISO date (inclusive)')->nullable(),
    ];
}
```

**Регистрация:** под `// System (8)` → `// System (10)` в AgentFleetServer.

**Verification:** `php artisan test --filter=MetricsToolTest`

---

### Unit C — AgentResetSessionTool

**Цел:** Агентите да могат да нулират runtime session на друг агент чрез MCP.

**Файл:** `app/Mcp/Tools/Agent/AgentResetSessionTool.php`

**Pattern:** `app/Mcp/Tools/Agent/AgentRuntimeStateTool.php` + `AgentController::resetRuntimeSession()`

```php
protected string $name = 'agent_reset_session';
protected string $description = 'Reset the runtime session of an agent — clears session_id so next execution starts fresh. Use when an agent is stuck in an old session context.';

public function schema(JsonSchema $schema): array
{
    return [
        'agent_id' => $schema->string()->description('Agent UUID')->required(),
    ];
}
```

**handle() логика:**
1. Намери агент с `Agent::find($agentId)` — провери team ownership
2. Намери `AgentRuntimeState` с `withoutGlobalScopes()`
3. Ако съществува: `$state->update(['session_id' => null])`
4. Return `['success' => true, 'agent_id' => $agentId, 'message' => 'Session cleared.']`

**Регистрация:** под `// Agent (12)` → `// Agent (13)`.

**Verification:** `php artisan test --filter=AgentResetSessionToolTest`

---

### Unit D — PUT /knowledge-bases/{id} REST endpoint

**Цел:** Позволи обновяване на name, description, agent_id на Knowledge Base.

**Нов Action:** `app/Domain/Knowledge/Actions/UpdateKnowledgeBaseAction.php`

```php
class UpdateKnowledgeBaseAction
{
    public function execute(
        KnowledgeBase $knowledgeBase,
        ?string $name = null,
        ?string $description = null,
        ?string $agentId = null,
    ): KnowledgeBase {
        if ($name !== null) $knowledgeBase->name = $name;
        if ($description !== null) $knowledgeBase->description = $description;
        if ($agentId !== null) $knowledgeBase->agent_id = $agentId;

        $knowledgeBase->save();

        activity()->performedOn($knowledgeBase)->log('knowledge_base.updated');

        return $knowledgeBase->fresh();
    }
}
```

**Controller метод** в `KnowledgeBaseController.php`:
```php
public function update(Request $request, KnowledgeBase $knowledgeBase, UpdateKnowledgeBaseAction $action): JsonResponse
{
    $validated = $request->validate([
        'name'        => ['sometimes', 'string', 'max:255'],
        'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
        'agent_id'    => ['sometimes', 'nullable', 'uuid', 'exists:agents,id'],
    ]);

    $kb = $action->execute(
        knowledgeBase: $knowledgeBase,
        name: $validated['name'] ?? null,
        description: array_key_exists('description', $validated) ? $validated['description'] : null,
        agentId: array_key_exists('agent_id', $validated) ? $validated['agent_id'] : null,
    );

    return response()->json(['data' => $kb]);
}
```

**Route** в `api_v1.php`:
```php
Route::put('/knowledge-bases/{knowledgeBase}', [KnowledgeBaseController::class, 'update']);
```

**Verification:** `php artisan test --filter=KnowledgeBaseUpdateTest`

---

### Unit E — GET /experiments/{id}/cost REST endpoint

**Цел:** REST API клиентите да могат да видят cost breakdown за даден experiment.

**Pattern:** Логиката вече съществува в `ExperimentCostTool` (MCP) — copy бизнес логиката в нов controller метод.

**Controller метод** в `ExperimentController.php`:
```php
public function cost(Experiment $experiment): JsonResponse
{
    // Събира cost от AiRun записите за всички stages на experiment-а
    // Групира по provider/model/stage_type
    // Връща totals + breakdown
}
```

**Внимание:** Провери какво точно прави `ExperimentCostTool::handle()` — копирай логиката директно, не я извиквай (MCP tools не са injectable services).

**Route** в `api_v1.php`:
```php
Route::get('/experiments/{experiment}/cost', [ExperimentController::class, 'cost']);
```

**Verification:** `php artisan test --filter=ExperimentCostTest`

---

### Unit F — DELETE /knowledge-graph/facts/{id} + KgInvalidateFactTool

**Цел:** Позволи инвалидиране (soft invalidation) на KG факт — не физическо изтриване, а маркиране с `invalid_at = now()`.

**Нов Action:** `app/Domain/KnowledgeGraph/Actions/InvalidateKgFactAction.php`

```php
class InvalidateKgFactAction
{
    public function execute(KgEdge $edge): KgEdge
    {
        $edge->update(['invalid_at' => now()]);
        return $edge->fresh();
    }
}
```

**Controller метод** в `KnowledgeGraphController.php`:
```php
public function destroy(Request $request, KgEdge $kgEdge, InvalidateKgFactAction $action): JsonResponse
{
    // Verify team ownership
    if ($kgEdge->team_id !== $request->user()->current_team_id) {
        abort(403);
    }

    $action->execute($kgEdge);

    return response()->json(['message' => 'Fact invalidated.', 'id' => $kgEdge->id]);
}
```

**Route** в `api_v1.php`:
```php
Route::delete('/knowledge-graph/facts/{kgEdge}', [KnowledgeGraphController::class, 'destroy']);
```

**Нов MCP Tool** — `app/Mcp/Tools/Signal/KgInvalidateFactTool.php`:
```php
protected string $name = 'kg_invalidate_fact';
protected string $description = 'Invalidate (soft-delete) a knowledge graph fact by its ID. Sets invalid_at = now(). The fact is retained for history but excluded from future searches.';

public function schema(JsonSchema $schema): array
{
    return [
        'fact_id' => $schema->string()->description('KgEdge UUID to invalidate')->required(),
    ];
}
```

**Verification:** `php artisan test --filter=KgInvalidateTest`

---

## System-Wide Impact

### Interaction Graph
- `UpdateKnowledgeBaseAction` → `activity()` audit log
- `InvalidateKgFactAction::execute()` → `kg_edges.invalid_at` → `KgSearchTool` и `SearchKgFactsAction` вече проверяват `whereNull('invalid_at')` така че автоматично ще изключват инвалидираните факти
- Нови MCP tools → `AgentFleetServer::$tools` → автоматично регистрирани при `mcp:start`

### Error Propagation
- MCP tools: `Response::error(...)` за not found / auth failures
- REST: model binding 404 за несъществуващи ресурси; 403 за wrong team

### State Lifecycle Risks
- `KgInvalidateFactTool`: само `invalid_at = now()` — не изтрива record. Безопасно, обратимо.
- `AgentResetSessionTool`: само `session_id = null` — ако агентът е в execution, следващото извикване ще стартира нов session. Не прекъсва текущ.

### API Surface Parity
- `UpdateKnowledgeBaseAction` → `KnowledgeBaseController::update()` + `KnowledgeBaseUpdateTool` трябва да се добави (или вече се покрива от `KnowledgeBaseCreateTool` с update mode?)
  - Провери дали `KnowledgeBaseCreateTool` поддържа update — ако не, добави `KnowledgeBaseUpdateTool` в `app/Mcp/Tools/Knowledge/`.

### Integration Test Scenarios
1. `PUT /knowledge-bases/{id}` с partial fields — само name се обновява, agent_id остава
2. `DELETE /knowledge-graph/facts/{id}` → последващ `POST /knowledge-graph/search` не връща инвалидирания факт
3. `agent_reset_session` MCP → `AgentRuntimeState::session_id` е `null` в DB след tool call
4. `system_metrics_aggregations` MCP с `period=daily` → връща MetricAggregation записи
5. `GET /experiments/{id}/cost` → same data като `experiment_cost` MCP tool за същия experiment

---

## Acceptance Criteria

### Functional
- [ ] `AgentFleetServer.version` е `1.13.0`
- [ ] `$instructions` включва всички 32 домейна
- [ ] KG tools са под `// KnowledgeGraph` коментар (и count-ът е коригиран)
- [ ] `system_metrics_aggregations` MCP tool работи с всички filter параметри
- [ ] `system_metrics_model_comparison` MCP tool връща `totals` + `by_model`
- [ ] `agent_reset_session` MCP tool нулира session_id на agent
- [ ] `PUT /api/v1/knowledge-bases/{id}` обновява name/description/agent_id
- [ ] `GET /api/v1/experiments/{id}/cost` връща cost breakdown
- [ ] `DELETE /api/v1/knowledge-graph/facts/{id}` инвалидира факт
- [ ] `kg_invalidate_fact` MCP tool инвалидира факт
- [ ] Инвалидираните факти не се появяват в KG search резултати

### Quality
- [ ] Всеки нов MCP tool е регистриран в `AgentFleetServer::$tools`
- [ ] `vendor/bin/pint --dirty` минава без грешки
- [ ] `php artisan test` — всички тестове минават

---

## Implementation Order

```
A → (B, C, F) паралелно → D, E → тестове → pint → push
```

Unit A първо (ServerFleet fixes), после B/C/F паралелно (нови tools), после D/E (REST endpoints).

---

## Sources & References

### Internal References
- `app/Mcp/Servers/AgentFleetServer.php:298-697` — tool registration patterns
- `app/Mcp/Tools/System/DashboardKpisTool.php` — read-only System tool pattern
- `app/Mcp/Tools/Agent/AgentRuntimeStateTool.php` — agent tool with ID lookup
- `app/Http/Controllers/Api/V1/MetricsController.php` — бизнес логиката за metrics
- `app/Http/Controllers/Api/V1/KnowledgeBaseController.php` — KB controller pattern
- `app/Http/Controllers/Api/V1/KnowledgeGraphController.php` — KG controller pattern
- `app/Domain/Knowledge/Actions/CreateKnowledgeBaseAction.php` — action pattern
- `app/Domain/KnowledgeGraph/Models/KgEdge.php` — invalid_at field pattern
- `routes/api_v1.php` — route registration style
