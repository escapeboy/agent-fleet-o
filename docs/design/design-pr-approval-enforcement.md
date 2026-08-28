# Design — enforce `pr.require_approval` at merge time

Date: 2026-08-28
Origin: review of agent-fleet-o#140 (MRTR migration) — found while verifying the
issue's claim that `GitPullRequestCreateTool` is a "gated" MCP call site.

## Problem

`GitRepository.config['pr']['require_approval']` is **write-only**. A repo-wide grep
(`base/`, `cloud/`, excluding vendor) finds three occurrences:

| File | Role |
|---|---|
| `GitRepositoryCreateTool.php:55` | schema description advertising the flag |
| `GitPullRequestCreateTool.php:83` | reads it to *create* an `ApprovalRequest` |
| `GitPullRequestCreateTool.php:23` | tool description promising "human review before merge" |

Nothing reads it to **enforce** anything. `GitPullRequestMergeTool` merges without
consulting `GitPullRequest.approval_request_id` or the linked `ApprovalRequest` status.

The independent risk gate (`GatedGitClient` → `GitOperationGate`, `mergePullRequest`
classified `high`) does not cover this: `GitOperationGate::resolvePolicy()` defaults to
`['low' => 'auto', 'medium' => 'auto', 'high' => 'auto']`. A team that never set
`settings.action_proposal_policy` — the default — gets no gate at all. So the documented
outcome for `require_approval: true` is:

> approval record created → merge proceeds unblocked → record stays `Pending` forever.

Two secondary defects in the same path:

- **Fail-open on approval creation.** `GitPullRequestCreateTool.php:99` is
  `catch (\Throwable) {}`. If `CreateApprovalRequestAction` throws, the PR is already
  created, `approval_request_id` stays null, and the tool returns
  `requires_approval: false`. The caller is told no approval is needed. No Sentry event
  either — the Throwable is swallowed at the only place it is observable.
- **Issue #140 mis-scopes the call site.** Its migration sketch (item 6) lists
  `GitPullRequestCreateTool` as a gated `tools/call` needing an MRTR resume path. It is
  not deferrable: the PR is created *before* the approval record, so there is no pending
  side effect to resume. The genuinely deferrable git seam is `GatedGitClient` /
  `GitOperationProposedException`.

## Non-goals

- MRTR / `input_required` / `requestState` (that is #140 itself, unchanged).
- Changing the default `action_proposal_policy`.
- `ActionProposal::isExpired()`. It is pending-gated and therefore useless as an
  execution guard, but it has **zero callers** (verified) and the execution chokepoint
  `ExecuteActionProposalJob::handle()` checks `expires_at` directly since #133. Leaving
  a dead footgun is out of scope for this fix; recorded in the issue thread instead.

## Design

### 1. Enforce at merge (fail-closed)

`GitPullRequestMergeTool::handle()` gains a pre-merge check, placed **before** the CI
validation and **outside** the `force` escape hatch:

```
if repo.config.pr.require_approval:
    pr  = GitPullRequest for (repo, pr_number)          # team-scoped
    req = pr?.approvalRequest
    missing        -> FAILED_PRECONDITION  (fail closed)
    Pending        -> FAILED_PRECONDITION
    Rejected       -> FAILED_PRECONDITION
    Expired / past expires_at -> FAILED_PRECONDITION
    Approved       -> proceed
```

`force` deliberately does **not** bypass this. `force` documents itself as "skip CI and
review validation checks"; a governance approval is neither. Making `force` bypass an
approval would reintroduce the hole through the front door.

Expiry is re-checked against `expires_at` at **merge** time, not trusted from the status
column — same invariant as `ExecuteActionProposalJob` (see
`mem:approval/action-proposal-execution-invariants`).

### 2. Close the fail-open at create

`GitPullRequestCreateTool`: report the Throwable (`report($e)`) so it reaches Sentry, and
return `requires_approval: true` with an `approval_error` field rather than a false
`false`. With (1) in place a missing approval record now blocks the merge, so a failed
creation degrades closed instead of open.

### 3. Model relation

None needed — `GitPullRequest::approvalRequest()` and the `approval_request_id`
column already exist and are already written. The gate reads `ApprovalRequest`
directly rather than through the relation so the team scope is explicit in the
query and larastan keeps the concrete type.

## Blast radius

- One new read of an existing column; no migration.
- Behaviour changes **only** for repos that opted into `pr.require_approval`. For every
  other repo the merge path is byte-identical.
- Repos already relying on the (broken) permissive behaviour will start being blocked —
  that is the intended correction, and it is the behaviour the tool description has
  claimed all along.

## Cloud layer

`GitPullRequestMergeTool` / `GitPullRequestCreateTool` have no `cloud/` override
(verified), so the base edit is live in cloud. Both tools are already registered; no MCP
registration change is required.
