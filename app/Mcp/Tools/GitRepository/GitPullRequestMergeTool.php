<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\Approval\Enums\ApprovalStatus;
use App\Domain\Approval\Models\ApprovalRequest;
use App\Domain\GitRepository\Models\GitPullRequest;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Mcp\Concerns\HasStructuredErrors;
use App\Mcp\Exceptions\InputRequiredException;
use App\Mcp\Methods\MultiRoundTripCallTool;
use App\Mcp\Protocol\RequestState;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class GitPullRequestMergeTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'git_pr_merge';

    protected string $description = 'Merge a pull request in a git repository. Supports squash, merge, and rebase strategies. By default validates CI status before merging. If the repository has pr.require_approval enabled, the merge is refused unless the linked ApprovalRequest is approved and unexpired — force does not bypass this.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'pr_number' => $schema->integer()
                ->description('Pull request number to merge')
                ->required(),
            'method' => $schema->string()
                ->description('Merge method: squash (default), merge, or rebase')
                ->enum(['squash', 'merge', 'rebase']),
            'commit_title' => $schema->string()
                ->description('Optional commit title for the merge commit'),
            'commit_message' => $schema->string()
                ->description('Optional commit message body for the merge commit'),
            'force' => $schema->boolean()
                ->description('Skip CI and mergeability validation checks (default: false). Does NOT bypass pr.require_approval.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = (app()->bound('mcp.team_id') ? app('mcp.team_id') : null) ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $repo = GitRepository::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('repository_id'));

        if (! $repo) {
            return $this->notFoundError('repository');
        }

        $prNumber = (int) $request->get('pr_number');

        // SEP-2322: "servers MUST always validate that state, as the client is
        // an untrusted intermediary." A state that does not decode, or that was
        // minted for another tenant/user/call, is refused outright rather than
        // silently ignored — it cannot grant anything either way (the gate
        // re-reads the approval row regardless), but a caller echoing a bogus
        // state has a bug worth surfacing.
        if ($stateError = $this->validateEchoedRequestState($teamId, $request->all())) {
            return $stateError;
        }

        // Governance gate. Runs before the client is resolved so a refused merge
        // performs no side effect at all, and outside the `force` escape hatch —
        // force skips CI/mergeability checks, never a human approval.
        if ($repo->config['pr']['require_approval'] ?? false) {
            if ($refusal = $this->approvalGate($repo, $prNumber, $teamId, $request->all())) {
                return $refusal;
            }
        }

        try {
            $client = app(GitOperationRouter::class)->resolve($repo);
            $force = (bool) $request->get('force', false);

            // Validate PR is mergeable unless force is set
            if (! $force) {
                $status = $client->getPullRequestStatus($prNumber);

                if ($status['mergeable'] === false) {
                    return $this->failedPreconditionError("PR #{$prNumber} is not mergeable (conflicts or state mismatch). Use force=true to bypass.");
                }

                if (! $status['ci_passing'] && $status['mergeable'] !== null) {
                    return $this->failedPreconditionError("PR #{$prNumber} has failing or pending CI checks. Use force=true to merge anyway.");
                }
            }

            $result = $client->mergePullRequest(
                $prNumber,
                $request->get('method', 'squash'),
                $request->get('commit_title'),
                $request->get('commit_message'),
            );

            // Update platform record if it exists
            GitPullRequest::where('git_repository_id', $repo->id)
                ->where('pr_number', (string) $prNumber)
                ->update(['status' => 'merged']);

            return Response::text(json_encode([
                'success' => true,
                'pr_number' => $prNumber,
                'sha' => $result['sha'],
                'merged' => $result['merged'],
                'message' => $result['message'],
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * Validate a `requestState` the client echoed back, if any.
     *
     * Returns an error Response when the caller sent one that this server did
     * not mint for this exact call, or null when there is nothing to validate
     * or the state checks out. Note the state is never *trusted* to authorise
     * anything — it only proves the caller is resuming the call it was given.
     *
     * @param  array<string, mixed>  $arguments
     */
    private function validateEchoedRequestState(string $teamId, array $arguments): ?Response
    {
        $blob = app()->bound(MultiRoundTripCallTool::BINDING_REQUEST_STATE)
            ? app(MultiRoundTripCallTool::BINDING_REQUEST_STATE)
            : null;

        if (! is_string($blob) || $blob === '') {
            return null;
        }

        $state = RequestState::decode($blob);

        if ($state === null) {
            return $this->invalidArgumentError('The supplied requestState is not valid or has expired. Retry the call without it to start a new request.');
        }

        $userId = auth()->id() === null ? null : (string) auth()->id();

        if (! $state->matches($this->name(), $arguments, $teamId, $userId)) {
            return $this->invalidArgumentError('The supplied requestState was issued for a different call. Retry without it to start a new request.');
        }

        return null;
    }

    /**
     * The `pr.require_approval` gate. Returns the response that must be sent
     * instead of merging, or null when an approved, unexpired ApprovalRequest
     * authorises it.
     *
     * Fails closed: a missing platform record or a missing approval link is a
     * refusal, not a pass. Expiry is re-derived from `expires_at` rather than
     * trusted from `status`, because ExpireStaleApprovals only sweeps pending
     * rows — an approved row can sit past its deadline with status Approved.
     *
     * A *pending* approval is the one resumable state, so it throws
     * InputRequiredException (SEP-2322) rather than returning: the caller gets
     * a non-terminal `input_required` with a requestState to retry with, or —
     * if it cannot speak MRTR — the same terminal error it got before.
     * Every other state is a decided outcome and stays terminal; looping a
     * rejected merge forever would be worse than refusing it.
     *
     * @param  array<string, mixed>  $arguments
     *
     * @throws InputRequiredException
     */
    private function approvalGate(GitRepository $repo, int $prNumber, string $teamId, array $arguments): ?Response
    {
        $pr = GitPullRequest::where('git_repository_id', $repo->id)
            ->where('pr_number', (string) $prNumber)
            ->first();

        if (! $pr) {
            return $this->failedPreconditionError("PR #{$prNumber} has no platform record, and this repository requires approval before merge. Create the PR through git_pr_create so an approval request is issued.");
        }

        // Scope the approval to the repository's team: an approval row from
        // another tenant must never authorise this merge.
        $approval = $pr->approval_request_id === null
            ? null
            : ApprovalRequest::withoutGlobalScopes()
                ->where('team_id', $repo->team_id)
                ->find($pr->approval_request_id);

        if (! $approval) {
            return $this->failedPreconditionError("PR #{$prNumber} has no approval request linked, and this repository requires approval before merge.");
        }

        if ($approval->status === ApprovalStatus::Pending) {
            throw InputRequiredException::forApproval(
                key: 'pr_merge_approval',
                message: "Merging PR #{$prNumber} in {$repo->name} needs approval. Approval request {$approval->id} is still pending — action it in FleetQ, then retry this call with the requestState.",
                state: RequestState::issue(
                    approvalRequestId: $approval->id,
                    teamId: $teamId,
                    userId: auth()->id() === null ? null : (string) auth()->id(),
                    tool: $this->name(),
                    arguments: $arguments,
                ),
                fallback: $this->failedPreconditionError("PR #{$prNumber} is awaiting approval (request {$approval->id} is pending). Approve it before merging."),
            );
        }

        if ($approval->status !== ApprovalStatus::Approved) {
            return $this->failedPreconditionError("PR #{$prNumber} cannot be merged: approval request {$approval->id} is {$approval->status->value}.");
        }

        if ($approval->expires_at !== null && $approval->expires_at->isPast()) {
            return $this->failedPreconditionError("Approval request {$approval->id} for PR #{$prNumber} expired at {$approval->expires_at->toIso8601String()}. Request a fresh approval before merging.");
        }

        return null;
    }
}
