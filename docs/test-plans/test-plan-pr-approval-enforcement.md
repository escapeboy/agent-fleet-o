# Test plan — `pr.require_approval` merge enforcement

Target: `base/tests/Feature/Mcp/Tools/GitRepository/GitPullRequestApprovalGateTest.php`

## Merge gate (`git_pr_merge`)

| # | Setup | Expect |
|---|---|---|
| 1 | `require_approval` off | merges (unchanged path — regression guard) |
| 2 | `require_approval` on, no `GitPullRequest` row | FAILED_PRECONDITION, client never called |
| 3 | `require_approval` on, row with `approval_request_id = null` | FAILED_PRECONDITION |
| 4 | approval `Pending` | FAILED_PRECONDITION |
| 5 | approval `Rejected` | FAILED_PRECONDITION |
| 6 | approval `Expired` | FAILED_PRECONDITION |
| 7 | approval `Approved` but `expires_at` in the past | FAILED_PRECONDITION (expiry re-checked at merge, not trusted from status) |
| 8 | approval `Approved`, `expires_at` null | merges |
| 9 | approval `Approved`, `expires_at` future | merges |
| 10 | `force = true` + approval `Pending` | FAILED_PRECONDITION — force must not bypass governance |
| 11 | approval belongs to another team | FAILED_PRECONDITION (team scoping) |

Assertion discipline: for every blocked case assert the **git client was not invoked**,
not merely that the response was an error. A gate that errors after merging is not a gate.

## Create fail-open (`git_pr_create`)

| # | Setup | Expect |
|---|---|---|
| 12 | `require_approval` on, `CreateApprovalRequestAction` throws | `requires_approval: true`, `approval_error` present, `report()` called; PR still returned |
| 13 | `require_approval` on, happy path | `approval_request_id` set, `requires_approval: true` |
| 14 | `require_approval` off | no approval record, `requires_approval: false` |

## Cross-checks

- 12 + 4 composed: a failed approval creation must leave the PR **unmergeable**
  (degrade closed), which is the whole point of pairing the two fixes.
