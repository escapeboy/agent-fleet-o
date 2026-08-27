# MCP coverage gaps — audit and closure (2026-08-27)

Audit of what the MCP server does *not* expose, and what was done about it.
Written because the most valuable finding here is not a bug but a piece of
architecture that is easy to get wrong twice: **the cloud MCP surface is a
deliberately curated subset of base, and the two layers disagree in three
different ways.** Read the last section before adding any MCP tool.

## Four gap classes found

| # | Class | Found | Action |
|---|---|---|---|
| A | Tool exists on disk, registered nowhere | 6 | registered |
| B | Lifecycle action on full server, unreachable via compact | 11 | wired (as whole families, 31 actions) |
| C | Compact description advertises an action `toolMap()` lacks | 4 | 2 wired, 2 built |
| D | Domain with UI but zero MCP surface | 1 (Inbox) | 5 tools built |

### A — unreachable tools

`project_snapshot_create` / `_list` / `_restore`, `experiment_activity_timeline`,
`experiment_sandbox_files`, `shadow_traffic_summary`. All were complete,
annotated, team-scoped classes that no server ever listed. Project Snapshots is
the notable one: model, migration, two domain actions and a `ProjectDetailPage`
button all shipped, with three MCP tools nobody could call.

**Not every unregistered tool is a bug.** `ProfilePasswordUpdateTool` is
unregistered on purpose — `AgentFleetServer.php` carries the comment *"password
changes must not be callable by an LLM"*. Each candidate was checked
individually rather than bulk-registered.

### B — the `crew_activate` class

`CrewActivateTool` was registered on the full server but missing from
`crew_manage`'s `toolMap()`. Because `CompactTool` builds the `action` parameter
as an **enum over `toolMap()` keys**, a missing entry is not merely undocumented
— it is unrepresentable. `WorkflowManageTool` already exposed `activate`, which
is what marked this as an oversight rather than policy.

Rule applied when wiring: **never add a destructive verb without its read
siblings.** `toolset_delete` without `toolset_list` lets an agent delete what it
cannot enumerate. Families were completed, not cherry-picked.

### C — descriptions that lie

Four compact tools documented an action their `toolMap()` did not implement.
This is worse than a plain omission: an agent reads the description, plans
against the action, and fails at call time. `crew_manage.delete` and
`workflow_manage.delete` had tool classes and were wired; `trigger_manage.get`
and `webhook_manage.get` had none and were built.

A both-directions consistency check over all 34 compact tools now passes:
no documented-but-unmapped actions, no mapped-but-undocumented actions.

### D — Inbox

`InboxPage` + 2 models + `RefineTriageWithLlmAction` + 5 migrations, no MCP.
Five tools added, wired into `approval_manage` rather than a 35th meta-tool so
the compact tool count stays at 34 — the whole point of that endpoint.

## Security: the self-approval guard

`ActionProposalApproveTool` had no guard against the approver being the actor.
`ActionProposal` is the gate in front of an agent's own side effects (git push,
integration actions, governed tool calls); over MCP the proposer and the caller
are routinely the same identity, since stdio runs as the team owner. An agent
could therefore raise a proposal and wave it through in the next tool call.

The guard lives **in the MCP tool, deliberately not in
`ApproveActionProposalAction`**. Through the web UI, a person approving a
proposal they themselves triggered is the normal human-in-the-loop flow —
guarding the shared domain action would break it. The UI stays the escape hatch.

`ActionProposal` approve/reject were also left **out** of `approval_manage` on
purpose. `approval_manage.approve` targets `ApprovalRequest` (human tasks and
outbound), a different model with no queue window. Widening compact access to
ActionProposal would weaken the gate, not fill a gap.

## The cloud/base split — read this before adding a tool

Three independent mechanisms, and they do not agree:

1. **`CloudAgentFleetServer extends Server`, not `AgentFleetServer`**, and
   declares its own `$tools`. It is a curated subset: **262 tools against base's
   670** — 234 shared, 28 cloud-only, **436 base tools absent from production
   `/mcp/full`** (`CrewActivateTool` among them). Registering a tool in base
   does *not* put it on fleetq.net.

2. **Compact actions do not need server registration.** `CompactTool::handle()`
   resolves the target with `app($map[$action])` and calls it directly. A
   granular tool reachable through a compact action works even when no server
   lists it.

3. **Some cloud compact tools override `toolMap()` and fully replace it** — no
   `parent::toolMap()` merge. `CloudToolManageTool`, `CloudSkillManageTool`,
   `CloudWebhookManageTool` and eight others. Additions to the base map are
   silently dropped for those; additions to a meta-tool cloud does *not*
   override flow through untouched.

Net effect of this change on production `/mcp`: the crew, workflow,
agent_advanced, evolution, signal, approval/inbox and trigger additions reach
it. The `toolset_*`, `federation_*`, `benchmark_*` and `webhook get` additions
do **not** — they live behind a replaced `toolMap()`. The six registered tools
do not either, having no compact action.

Closing that remainder means expanding the production MCP surface, which is a
product decision rather than a bug fix — `tool_federation_enable` in particular
is cross-team in a multi-tenant SaaS. It was deliberately left open.
