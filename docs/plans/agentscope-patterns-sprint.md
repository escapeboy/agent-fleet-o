# AgentScope Pattern Adoption — Sprint Plan

**Date**: 2026-04-02
**Scope**: 5 features inspired by AgentScope research

## Feature 1: Structured Memory Compression (SummarySchema)

**Current state**: `ConversationCompactor` already compacts at 40 messages, producing free-form 2KB resume.

**Changes**:
- Create `MemorySummarySchema` DTO with structured fields (task_overview, current_state, key_discoveries, next_steps, context_to_preserve)
- Update `ConversationCompactor::synthesizeResume()` to use structured schema with JSON output
- Add `CrewContextCompactor` service for crew execution context management
- Add compression stats to AssistantConversation metadata
- UI: Compression badge in assistant panel header

**Files**: ConversationCompactor.php, MemorySummarySchema DTO, CrewContextCompactor service

## Feature 2: Agent-level Hooks (AOP)

**Current state**: `ExecuteAgentAction` has hardcoded Pipeline + events. No user-configurable hooks.

**Changes**:
- New enum: `AgentHookPosition` (pre_execute, post_execute, pre_reasoning, post_reasoning, on_tool_call, on_error)
- New enum: `AgentHookType` (prompt_injection, output_transform, filter, guardrail, notification)
- New model: `AgentHook` (team_id, agent_id nullable, position, type, name, config JSONB, priority, enabled)
- Migration: `create_agent_hooks_table`
- Service: `AgentHookExecutor::run(position, context): context`
- Integration: Execute hooks in `ExecuteAgentAction` at each lifecycle point
- UI: Hook management section on Agent detail page
- MCP tools: CRUD for agent hooks

## Feature 3: Fanout/Gather for Crews

**Current state**: `CrewProcessType` has Sequential, Parallel (dependency-graph), Hierarchical, SelfClaim, Adversarial.

**Changes**:
- Add `Fanout` case to `CrewProcessType` enum
- Add `FanoutGatherStrategy` to CrewOrchestrator — same input to ALL agents, gather all outputs
- Add `SynthesizeFanoutResultsAction` — aggregates parallel outputs into final result
- UI: Add Fanout option in crew process type selector with description
- MCP tools: Already covered by existing crew tools

## Feature 4: MsgHub (Chat Room) for Crews

**Current state**: No shared message bus. Crews use task-based execution.

**Changes**:
- Add `ChatRoom` case to `CrewProcessType` enum
- New model: `CrewChatMessage` (crew_execution_id, agent_id, content, role, round, metadata)
- Migration: `create_crew_chat_messages_table`
- Service: `CrewChatRoomOrchestrator` — manages rounds, participant turns, convergence detection
- Integration into `CrewOrchestrator::run()` for ChatRoom process type
- UI: Chat room timeline in crew execution page
- MCP tools: List/read chat messages

## Feature 5: Tool Middleware Pipeline

**Current state**: PrismAiGateway has onion middleware. Tool execution has no middleware.

**Changes**:
- New contract: `ToolExecutionMiddlewareInterface::handle(ToolExecutionContext, Closure): ToolExecutionResult`
- New DTO: `ToolExecutionContext` (tool, agent, input, team_id, execution metadata)
- New DTO: `ToolExecutionResult` (output, cost, duration, metadata)
- Built-in middleware: `ToolBudgetCheck`, `ToolAuditLog`, `ToolRateLimit`, `ToolInputValidation`
- New model: `ToolMiddlewareConfig` (tool_id, middleware_class, config, priority, enabled)
- Migration: `create_tool_middleware_configs_table`
- Service: `ToolMiddlewarePipeline::execute(context): result` — same array_reduce pattern as PrismAiGateway
- Integration: Wrap tool execution in ExecuteAgentAction with middleware pipeline
- UI: Middleware config section on Tool detail page
- MCP tools: CRUD for tool middleware configs
