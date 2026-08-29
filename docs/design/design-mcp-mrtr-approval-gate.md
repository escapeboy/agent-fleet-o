# Design — MRTR (SEP-2322) for the approval gate

Date: 2026-08-29
Closes: agent-fleet-o#140

## Source of truth

SEP-2322 "Multi Round-Trip Requests" is **Final** (created 2026-02-03; Roth / McCaffrey /
Zimmerman). The wire shape below is quoted from the spec, not paraphrased from the issue —
the issue's summary was accurate but incomplete on the security MUSTs, which drive the design.

Server → client:

```ts
export interface InputRequiredResult extends Result {
  inputRequests?: InputRequests;   // MAY
  requestState?: string;           // MAY — opaque to the client
}
```

```json
{"jsonrpc":"2.0","id":2,"result":{
  "resultType":"input_required",
  "inputRequests":{"<key>":{"method":"elicitation/create","params":{…}}},
  "requestState":"<opaque>"}}
```

Client → server on retry (a *new, independent* request):

```ts
export interface InputResponseRequestParams extends RequestParams {
  inputResponses?: InputResponses; // keyed by the same keys the server issued
  requestState?: string;           // echoed verbatim
}
```

`Result` gains `resultType`. **Absent ⇒ the client assumes `"complete"`**, which is what makes
this backward compatible: we only ever add the field on the `input_required` path.

## The MUSTs that decide the design

> If a request contains a `requestState` field, servers **MUST** always validate that state, as
> the client is an untrusted intermediary. … Servers **SHOULD** encrypt … to ensure both
> confidentiality and integrity. … if the request state contains any data that is specific to
> the original user, the server **MUST** use some mechanism to cryptographically bind the data
> to the original user and **MUST** verify that the `requestState` data sent by the client is
> associated with the currently authenticated user.

This settles the fork the issue left open ("signed-and-stateless or a DB-backed handle").

**Neither alone. An encrypted envelope over a DB-backed id.**

A bare `ApprovalRequest` id fails the binding MUST — any authenticated tenant could echo
another tenant's id. A purely self-contained blob would make the approval decision live in a
token the client holds, which is worse: the decision must stay in `approval_requests`, where
the inbox, the audit trail and `ExpireStaleApprovals` already operate on it.

So `requestState` carries an envelope that is *cheap to verify statelessly* and *authoritative
only by reference*:

```
{ v, approval_id, team_id, user_id, tool, args_hash, exp }  →  Crypt::encryptString(json)
```

- **Encryption**: Laravel `Crypt` (AES-256-CBC + HMAC) — satisfies "confidentiality and
  integrity" without hand-rolling AES-GCM or a JWT library.
- **Binding**: `team_id` + `user_id` are compared against the *currently authenticated*
  caller on every decode. Mismatch ⇒ treated as absent, not as an error hint.
- **Replay across calls**: `tool` + `args_hash` pin the envelope to the exact call it was
  issued for, so a state minted for `git_pr_merge` on PR #7 cannot resume PR #9.
- **Expiry**: `exp` is an envelope TTL, deliberately *separate* from the approval's own
  `expires_at`. The envelope going stale is a protocol-level "start over"; the approval
  expiring is a governance decision. Both are checked; they answer different questions.
- **Statelessness (SEP-2567)**: verification needs only the envelope + the row it names. No
  session affinity, no in-memory state — any node behind the LB can serve the retry.

## Authority model — the part that is easy to get wrong

`inputRequests` carries an `elicitation/create` so the caller's human sees *what* is blocked
and where to act. **The elicitation answer is a nudge, never the authorisation.** On retry we
re-read `approval_requests` and decide from the row. A client that answers "I approved it"
without an approved row still gets `input_required` back.

This keeps one source of truth and means a compromised or buggy client cannot talk its way
past the gate — it can only ask us to look again.

Declining the elicitation (`action: "decline"` / `"cancel"`) does **not** reject the approval
either; it ends the round-trip for this caller and leaves the row for a human. Rejecting on a
client's say-so would let any caller burn another operator's pending decision.

## Components

| Component | Role |
|---|---|
| `ProtocolVersions::supportsMultiRoundTrip()` | gate on `2026-07-28`, same shape as the cache-hints gate |
| `ProtocolContext::supportsMultiRoundTrip()` | per-request answer |
| `Protocol\RequestState` | the envelope: `issue()` / `decode()` / `matches()` |
| `Exceptions\InputRequiredException` | what a gated tool throws; carries `inputRequests`, the envelope, and a **legacy fallback `Response`** |
| `Methods\MultiRoundTripCallTool extends CallTool` | binds `inputResponses`/`requestState` for the tool; converts the exception to the MRTR result — or to the fallback when the client is pre-MRTR |

The fallback on the exception is what keeps backward compatibility a single code path: the
tool always throws, and the *method* decides whether the caller gets `input_required` or
today's terminal `FAILED_PRECONDITION`. No `if (supportsMrtr())` scattered through tools.

## Call site

`git_pr_merge` under `pr.require_approval` — the gate shipped in #146. It is the honest
exemplar the issue was reaching for: the effect is genuinely deferred, the approval row
already exists, and the states map cleanly.

| Approval state | MRTR result |
|---|---|
| missing row / missing link | terminal error (fail closed — nothing to wait for) |
| `Pending` | `input_required` + elicitation + envelope |
| `Approved`, unexpired | perform the merge |
| `Approved`, past `expires_at` | terminal error |
| `Rejected` / `Expired` | terminal error |

Note the asymmetry: only `Pending` is resumable. Everything else is a decided outcome, and the
spec's own error guidance says a decided-but-unusable state is a terminal error, not another
round trip — otherwise a rejected merge would loop forever.

**`GitPullRequestCreateTool` is deliberately NOT migrated.** The issue's item 6 listed it, but
the PR is created upstream *before* the approval row exists, so there is no pending side effect
to resume. Corrected in the issue thread; the deferrable git seam is `GatedGitClient`.

## Out of scope

Elicitation for anything other than approval gates (credential prompts, missing arguments), and
the persistent/Tasks workflow in the second half of SEP-2322 — FleetQ's approval gates are
ephemeral in the SEP's sense.
