# Test plan — MRTR (SEP-2322) approval gate

Two levels, because the feature has two failure surfaces: the envelope's crypto/binding
properties, and the method↔tool seam where the protocol shape is actually produced.

## Unit — `tests/Unit/Mcp/Protocol/RequestStateTest.php`

The envelope is a security boundary; every case here is a MUST from the SEP.

| # | Case | Expect |
|---|---|---|
| 1 | encode → decode | fields round-trip |
| 2 | inspect the blob | contains neither the approval id nor the tool name (opaque + no internal id leak) |
| 3 | garbage / empty / null input | `null`, never an exception |
| 4 | ciphertext with mutated tail | `null` (integrity) |
| 5 | valid ciphertext, foreign payload shape / wrong version / non-JSON | `null` |
| 6 | past `exp` | `null` |
| 7 | same tool + args + team + user | `matches()` true |
| 8 | arguments re-serialised in another key order | true — clients may not preserve order |
| 9 | different `pr_number` | false — a state for #7 must not resume #9 |
| 10 | different tool name | false |
| 11 | different team | false |
| 12 | different user, and anonymous replaying a user-bound state | false |
| 13 | state minted with `userId: null` (stdio/machine token) | team-bound only: any user of that team resumes, another team does not |
| 14 | nested maps reordered vs list reordered | map order ignored, **list order significant** |

Case 13 is the one that is easy to get backwards: binding to a user that never existed would
make stdio callers unable to resume at all.

## Feature — `tests/Feature/Mcp/McpMultiRoundTripTest.php`

Driven through `MultiRoundTripCallTool::handle()`, not the tool, because the conversion is the
thing under test. `mcp.request` must be bound first — `Server::handleRequest` does that in
production and the McpServiceProvider `resolving` callback populates the tool's `Request` from
it; a test that skips it silently gets an argument-less tool.

| # | Case | Expect |
|---|---|---|
| 1 | pending approval, 2026-07-28 client | `resultType: input_required`, non-empty `requestState`, `inputRequests.pr_merge_approval.method = elicitation/create`, **no** `isError` key |
| 2 | approved, 2026-07-28 client | **no** `resultType` — absent ⇒ "complete", so success stays byte-identical |
| 3 | pending, 2025-06-18 client | no `resultType`, no `requestState`, `isError: true`, message unchanged from pre-MRTR |
| 4 | pending → approve out of band → retry with state | merges |
| 5 | pending → retry with state, still pending | `input_required` again |
| 6 | retry claiming `acknowledged: true` against a pending row | `input_required` — the elicitation is a nudge, never the authorisation |
| 7 | rejected approval | terminal error, no `resultType` — a decided outcome must not loop |
| 8 | `requestState: "not-a-real-envelope"` | error mentioning "not valid" |
| 9 | state minted for `pr_number: 999` | error mentioning "different call" |
| 10 | state minted for another team | error |
| 11 | state minted for another user | error |

Case 6 is the security assertion of the whole feature. Cases 8–11 are the "servers MUST always
validate that state" requirement.

## Regression — `GitPullRequestApprovalGateTest`

The pending cases changed contract: the tool now *throws* `InputRequiredException` rather than
returning a terminal error, so those two assert the exception. Everything else is unchanged,
and every refusal path still binds a client with `shouldNotReceive('mergePullRequest')` —
a gate that errors after merging is not a gate.

## Not covered, deliberately

- The persistent/Tasks half of SEP-2322 (`tasks/result`, `TaskInputResponseRequest`). FleetQ's
  approval gates are ephemeral in the SEP's sense.
- Multiple concurrent `inputRequests` keys. The approval gate issues exactly one.
