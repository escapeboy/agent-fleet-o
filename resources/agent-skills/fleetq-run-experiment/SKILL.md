---
name: fleetq-run-experiment
description: Create, run, monitor, and recover a FleetQ experiment through its 20-state pipeline via MCP tools or the REST API.
---

# Run a FleetQ experiment

An *experiment* is FleetQ's unit of autonomous work: it moves through a
20-state pipeline (scoring → planning → building → approval → executing →
metrics → evaluation) with budget enforcement and an audit trail at every
step.

Requires a working connection — see the `fleetq-connect` skill first.

## Create and start

MCP tools:

| Tool | Purpose |
|---|---|
| `experiment_create` | Create the experiment. |
| `experiment_start` | Move it out of draft into the pipeline. |
| `experiment_get` | Read current state, stage, and cost. |
| `experiment_list` | Enumerate experiments for the team. |

REST equivalent:

```
POST {{APP_URL}}/api/v1/experiments
POST {{APP_URL}}/api/v1/experiments/{id}/transition
GET  {{APP_URL}}/api/v1/experiments/{id}
```

## Monitor progress

- `experiment_steps` — per-step execution detail.
- `experiment_stage_telemetry` — timing and stage-level metrics.
- `experiment_cost` — credits consumed so far.
- `experiment_valid_transitions` — which transitions are legal right now.
  Call this before attempting a transition; the state machine rejects
  anything not in the map.

## Intervene

| Situation | Tool |
|---|---|
| Needs to stop temporarily | `experiment_pause` / `experiment_resume` |
| Needs to stop permanently | `experiment_kill` |
| A stage failed and you want another attempt | `experiment_retry` |
| A specific step failed | `experiment_retry_from_step` (resets that step and everything downstream) |
| Long-running work was interrupted | `experiment_resume_from_checkpoint` |
| You want to redirect it mid-flight | `experiment_steer` |
| You want to understand a failure | `experiment_diagnose` |

## Rules that will bite you

- **Transitions are validated.** `ExperimentTransitionMap` rejects illegal
  moves — always check `experiment_valid_transitions` rather than guessing.
- **Terminal states are final**: `completed`, `killed`, `discarded`,
  `expired`. There is no transition out; create a new experiment.
- **Budget is enforced before execution.** An experiment pauses
  automatically when the team's credit balance cannot cover the reservation
  (reservations use a 1.5× multiplier). Check `budget_summary` first if you
  expect long runs.
- **Approval gates block execution.** If the experiment enters
  `awaiting_approval`, a human (or an agent with approval permission) must
  act via `approval_approve` before it proceeds.
