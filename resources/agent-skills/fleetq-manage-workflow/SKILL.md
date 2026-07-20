---
name: fleetq-manage-workflow
description: Build, validate, cost, and execute FleetQ visual DAG workflows — including generating one from a natural-language goal.
---

# Manage a FleetQ workflow

A *workflow* is a reusable directed graph of work. Nodes are typed:
`start`, `end`, `agent`, `conditional`, `human_task`, `switch`,
`dynamic_fork`, `do_while`. Workflows are templates; running one
materialises an experiment.

Requires a working connection — see the `fleetq-connect` skill first.

## Fastest path: generate from a goal

`workflow_generate` decomposes a natural-language goal into a graph. Use it
as a starting point, then refine — it produces a draft, not a final answer.

## Build by hand

| Tool | Purpose |
|---|---|
| `workflow_create` | Create the workflow shell. |
| `workflow_node_add` / `workflow_node_update` / `workflow_node_delete` | Manage nodes. |
| `workflow_edge_add` / `workflow_edge_delete` | Manage edges. |
| `workflow_save_graph` | Persist a whole graph in one call. |

REST equivalent:

```
POST {{APP_URL}}/api/v1/workflows
PUT  {{APP_URL}}/api/v1/workflows/{id}/graph
```

## Always validate before activating

```
workflow_validate   →  structural check (unreachable nodes, missing start/end, cycles)
workflow_estimate_cost  →  projected credit cost per run
workflow_simulate   →  dry run without spending credits or sending outbound
workflow_activate   →  make it runnable
```

`workflow_activate` on an invalid graph fails. Run `workflow_validate`
first and fix what it reports — the errors name the offending node.

## Execute and observe

- `workflow_execution_chain` — trace what ran, in order.
- `workflow_snapshot_list` — historical versions of the graph.
- `workflow_compensation_runs` — compensating actions triggered on failure.

## Portability

`workflow_export_yaml` / `workflow_import_yaml` move a workflow between
teams or environments as plain YAML. `workflow_duplicate` copies within a
team.

## Rules that will bite you

- **`human_task` nodes block.** They create an approval request and wait.
  An unattended agent run will stall there until a human completes it, or
  until the SLA check escalates or expires the task.
- **`switch` nodes route on `case_value` on the outgoing edges.** An edge
  with no matching case is never taken; a switch with no matching edge
  dead-ends the branch.
- **Cost estimates are projections, not caps.** Budget enforcement happens
  at execution time against the team's real balance.
