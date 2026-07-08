<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\DTOs\WarmBuildCandidate;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Services\WarmBuildCandidatePolicy;
use App\Domain\Experiment\Services\WarmBuildCandidateScorer;
use App\Domain\GitRepository\DTOs\WarmBuildChangeset;
use App\Domain\GitRepository\DTOs\WritableRootsGrant;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\ChangesetPolicyValidator;
use App\Domain\GitRepository\Services\GitCloneUrlResolver;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\GitRepository\Services\WarmRepoManager;
use App\Domain\GitRepository\Services\WritableRootsPolicy;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Infrastructure\AI\Gateways\LocalAgentGateway;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * Platform-side debug-track builder: instead of waiting for an external bridge
 * agent, this checks the target repo out into a warm worktree on the VPS, runs
 * claude-code-vps agentically inside it to apply the fix, then commits, pushes a
 * branch, opens a DRAFT pull request, and completes the building stage.
 *
 * Best-of-N (Shepherd borrow #1): when experiments.warm_build.candidates > 1 the
 * agent is run N times from the same base ref, each in its own worktree; the
 * candidate changesets are scored deterministically (WarmBuildCandidateScorer)
 * and only the winner is pushed into the single draft PR. N=1 (default) is
 * behaviourally identical to the legacy single-run path. Every candidate's
 * changeset is validated against the writable-roots grant (#3); a scope-violating
 * change loses to a compliant one and, if it still wins, is flagged in the PR.
 *
 * Dispatched from RunBuildingStage only when experiments.warm_build.enabled is on
 * AND a repository can be resolved; otherwise the legacy bridge-wait path stands.
 * Any failure transitions the experiment to BuildingFailed with a safe reason.
 */
class ExecuteWarmDebugBuildAction
{
    public function __construct(
        private readonly WarmRepoManager $warmRepo,
        private readonly GitCloneUrlResolver $cloneUrls,
        private readonly GitOperationRouter $gitRouter,
        private readonly CompleteBuildingAction $completeBuilding,
        private readonly TransitionExperimentAction $transition,
        private readonly WritableRootsPolicy $writableRoots,
        private readonly ChangesetPolicyValidator $changesetValidator,
        private readonly WarmBuildCandidatePolicy $candidatePolicy,
        private readonly WarmBuildCandidateScorer $scorer,
    ) {}

    public function execute(Experiment $experiment): void
    {
        if ($experiment->track !== ExperimentTrack::Debug || $experiment->status !== ExperimentStatus::Building) {
            return;
        }

        $repo = $this->resolveRepository($experiment);
        if (! $repo) {
            $this->fail($experiment, 'No git repository is configured for this experiment\'s agent.');

            return;
        }

        $ref = 'origin/'.($repo->default_branch ?: 'main');
        $cloneUrl = $this->cloneUrls->authenticatedUrl($repo);
        $grant = $this->writableRoots->resolve($repo, $experiment);
        $n = $this->candidatePolicy->count($repo);

        /** @var list<WarmBuildCandidate> $candidates */
        $candidates = [];
        /** @var list<string> $worktrees */
        $worktrees = [];
        $prUrls = [];
        $winner = null;

        // Build phase: any failure here flips the experiment to BuildingFailed.
        // Completion (the AwaitingApproval transition) is deliberately AFTER this
        // block so a throwing downstream transition listener can't be mistaken
        // for a build failure and re-transition an already-completed experiment.
        try {
            for ($i = 1; $i <= $n; $i++) {
                try {
                    $candidates[] = $this->runCandidate($experiment, $repo, $ref, $cloneUrl, $grant, $i, $worktrees);
                } catch (VpsLocalAgentException $e) {
                    // Transient capacity (VPS concurrency cap): the slot is acquired
                    // before any agent work, so nothing was spent. If we already have
                    // a usable candidate, stop and select among what we have; if none
                    // yet, surface it so the job re-dispatches after a backoff.
                    if ($e->retryable) {
                        if ($this->hasViable($candidates)) {
                            break;
                        }
                        throw $e;
                    }
                    $candidates[] = $this->failedCandidate($i, $experiment, $e, $cloneUrl);
                } catch (\Throwable $e) {
                    // One bad candidate must not sink the whole build — record it and
                    // let the remaining candidates (or none) decide the outcome.
                    $candidates[] = $this->failedCandidate($i, $experiment, $e, $cloneUrl);
                }
            }

            $winner = $this->scorer->pick($candidates);
            if ($winner === null || $winner->worktree === null) {
                $this->fail($experiment, $this->noUsableChangeReason($candidates));

                return;
            }

            $this->git($winner->worktree, ['push', 'origin', 'HEAD:refs/heads/'.$winner->branch(), '--force']);

            $pr = $this->gitRouter->resolve($repo)->createPullRequest(
                title: '[FleetQ] Fix: '.$experiment->title,
                body: $this->prBody($experiment, $winner, count($candidates)),
                head: $winner->branch(),
                base: (string) ($repo->default_branch ?: 'main'),
                draft: true,
            );
            $prUrls = array_filter([$pr['pr_url']]);
        } catch (\Throwable $e) {
            if ($e instanceof VpsLocalAgentException && $e->retryable) {
                throw $e;
            }

            // Never leak an authenticated clone URL into the failure reason/logs.
            $this->fail($experiment, 'Warm build failed: '.$this->scrub($e->getMessage(), $cloneUrl));

            return;
        } finally {
            foreach ($worktrees as $wt) {
                $this->warmRepo->release($repo, $wt);
            }
            $this->warmRepo->prune($repo);
        }

        $this->completeBuilding->execute(
            experiment: $experiment,
            prUrls: $prUrls,
            summary: $this->summary($repo, count($candidates)),
            completedBy: 'agent_warm_build',
        );

        Log::info('ExecuteWarmDebugBuildAction: draft PR opened', [
            'experiment_id' => $experiment->id,
            'repo_id' => $repo->id,
            'pr_urls' => $prUrls,
            'candidates' => count($candidates),
            'winner_index' => $winner->index,
            'winner_within_roots' => $winner->withinRoots,
        ]);
    }

    /**
     * Run a single best-of-N candidate: check out its own worktree, run the agent
     * in it, capture + score the changeset. The worktree is tracked in $worktrees
     * (by reference) so the caller releases every candidate's tree in finally{}.
     *
     * @param  list<string>  $worktrees
     */
    private function runCandidate(
        Experiment $experiment,
        GitRepository $repo,
        string $ref,
        ?string $cloneUrl,
        WritableRootsGrant $grant,
        int $index,
        array &$worktrees,
    ): WarmBuildCandidate {
        // Distinct per-candidate run id → distinct worktree slot + branch, so
        // concurrent candidates never collide on the shared .git/worktrees/ state.
        $runId = $experiment->id.'-c'.$index;
        $worktree = $this->warmRepo->checkout($repo, $ref, $runId, $cloneUrl);
        $worktrees[] = $worktree;

        $baseSha = $this->git($worktree, ['rev-parse', 'HEAD']);

        // Resolve by class (not constructor DI): the cloud edition binds
        // LocalAgentGateway::class to DisabledLocalAgentGateway (implements
        // AiGatewayInterface, not a subclass — DI-hinting the concrete class
        // would TypeError). Disabled delegates vps_only providers to a real
        // gateway, so calling complete() directly preserves workingDirectory.
        app(LocalAgentGateway::class)->complete($this->buildRequest($experiment, $worktree, $grant));

        // The agent edits files with the CLI's own tools; capture whatever it
        // changed as a single commit (a no-op when it already committed).
        if (trim($this->git($worktree, ['status', '--porcelain'])) !== '') {
            $this->git($worktree, ['add', '-A']);
            $this->git($worktree, [
                '-c', 'user.email=agent@fleetq.ai',
                '-c', 'user.name=FleetQ Agent',
                'commit', '-m', 'Fix: '.$experiment->title,
            ]);
        }

        $changeset = WarmBuildChangeset::capture($worktree, $baseSha);

        return new WarmBuildCandidate(
            index: $index,
            runId: $runId,
            worktree: $worktree,
            changeset: $changeset,
            withinRoots: $this->changesetValidator->isWithinRoots($changeset, $grant),
            verify: $this->verify($worktree, $repo, $changeset),
        );
    }

    /**
     * Optional per-repo verification (tests/lint) for the changeset. Runs the
     * team-configured command in the candidate's worktree; pass/fail feeds the
     * scorer. Absent command → UNKNOWN (neutral). Never throws — a broken verify
     * command must not fail the build, only rank the candidate.
     */
    private function verify(string $worktree, GitRepository $repo, WarmBuildChangeset $changeset): int
    {
        if (! $changeset->hasChanges()) {
            return WarmBuildCandidate::VERIFY_UNKNOWN;
        }

        $command = $repo->config['warm_build_verify_command'] ?? null;
        if (! is_string($command) || trim($command) === '') {
            return WarmBuildCandidate::VERIFY_UNKNOWN;
        }

        try {
            $result = Process::path($worktree)
                ->timeout((int) config('experiments.warm_build.verify_timeout_seconds', 300))
                ->run($command);

            return $result->successful() ? WarmBuildCandidate::VERIFY_PASS : WarmBuildCandidate::VERIFY_FAIL;
        } catch (\Throwable $e) {
            Log::info('ExecuteWarmDebugBuildAction: verify command errored', ['error' => $e->getMessage()]);

            return WarmBuildCandidate::VERIFY_FAIL;
        }
    }

    /**
     * Terminal failure for the case where the VPS concurrency cap never cleared
     * within the retry budget (called by RunWarmDebugBuildJob).
     */
    public function failCapacityExhausted(Experiment $experiment): void
    {
        $this->fail($experiment, 'Warm build deferred: VPS capacity unavailable after repeated retries.');
    }

    /**
     * @param  list<WarmBuildCandidate>  $candidates
     */
    private function hasViable(array $candidates): bool
    {
        foreach ($candidates as $candidate) {
            if ($candidate->hasChanges()) {
                return true;
            }
        }

        return false;
    }

    private function failedCandidate(int $index, Experiment $experiment, \Throwable $e, ?string $cloneUrl): WarmBuildCandidate
    {
        $reason = $this->scrub($e->getMessage(), $cloneUrl);
        Log::warning('ExecuteWarmDebugBuildAction: candidate failed', [
            'experiment_id' => $experiment->id,
            'candidate' => $index,
            'reason' => $reason,
        ]);

        return new WarmBuildCandidate(
            index: $index,
            runId: $experiment->id.'-c'.$index,
            worktree: null,
            changeset: null,
            withinRoots: false,
            failed: true,
            reason: $reason,
        );
    }

    /**
     * @param  list<WarmBuildCandidate>  $candidates
     */
    private function noUsableChangeReason(array $candidates): string
    {
        foreach ($candidates as $candidate) {
            if ($candidate->failed && $candidate->reason !== null) {
                return 'Warm build failed: '.$candidate->reason;
            }
        }

        return 'Agent produced no changes — nothing to open a PR for.';
    }

    private function resolveRepository(Experiment $experiment): ?GitRepository
    {
        $repoId = $experiment->constraints['git_repository_id'] ?? null;

        if (! $repoId && $experiment->agent_id) {
            $agent = Agent::withoutGlobalScopes()->find($experiment->agent_id);
            $repoId = $agent?->config['git_repository_ids'][0] ?? null;
        }

        if ($repoId) {
            return GitRepository::withoutGlobalScopes()
                ->where('team_id', $experiment->team_id)
                ->find($repoId);
        }

        // No explicit repo — e.g. a retry of an experiment delegated before a repo
        // was configured. Fall back to the team default repo, or its single repo.
        // Mirrors DelegateBugReportToAgentAction::resolveGitRepositoryId so both
        // the delegation and warm-build paths resolve the same target.
        $repos = GitRepository::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->get();

        return $repos->firstWhere('is_default', true)
            ?? ($repos->count() === 1 ? $repos->first() : null);
    }

    private function buildRequest(Experiment $experiment, string $worktree, WritableRootsGrant $grant): AiRequestDTO
    {
        $system = 'You are a senior software engineer fixing a reported bug in the checked-out '
            .'repository (your current working directory). Make the smallest correct change that '
            .'resolves the issue. If the project has tests, run them and ensure they pass. Do NOT '
            .'push or open a pull request — that is handled for you. When done, stop.';

        $user = trim($experiment->title."\n\n".($experiment->thesis ?? ''));

        return new AiRequestDTO(
            provider: 'claude-code-vps',
            model: (string) config('local_agents.vps.build_model', ''),
            systemPrompt: $system,
            userPrompt: $user,
            teamId: $experiment->team_id,
            experimentId: $experiment->id,
            purpose: 'experiment.debug_build',
            workingDirectory: $worktree,
            writableRoots: $this->writableRoots->absoluteWritablePaths($grant, $worktree),
        );
    }

    private function prBody(Experiment $experiment, WarmBuildCandidate $winner, int $n): string
    {
        $body = "Automated fix opened by FleetQ for experiment `{$experiment->id}`.\n\n"
            ."**Issue:** {$experiment->title}\n\n";

        if ($n > 1) {
            $files = count($winner->changeset->files ?? []);
            $lines = ($winner->changeset->added ?? 0) + ($winner->changeset->removed ?? 0);
            $roots = $winner->withinRoots ? 'within writable-roots' : '⚠️ writable-roots violation';
            $body .= "Selected candidate {$winner->index} of {$n} (best-of-N): {$roots}, "
                ."{$files} file(s) / {$lines} line(s) changed.\n\n";
        }

        if (! $winner->withinRoots) {
            $body .= "⚠️ This change touches paths outside the configured writable-roots — review carefully.\n\n";
        }

        return $body.'⚠️ Draft PR — requires human review before merge.';
    }

    private function summary(GitRepository $repo, int $n): string
    {
        return $n > 1
            ? "Warm-build agent opened a draft PR on {$repo->name} (best of {$n})."
            : 'Warm-build agent opened a draft PR on '.$repo->name.'.';
    }

    private function fail(Experiment $experiment, string $reason): void
    {
        $stage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', StageType::Building)
            ->where('status', StageStatus::Running)
            ->latest()
            ->first();

        $stage?->update([
            'status' => StageStatus::Failed,
            'completed_at' => now(),
            'output_snapshot' => array_merge($stage->output_snapshot ?? [], ['error' => $reason]),
        ]);

        if ($experiment->status === ExperimentStatus::Building) {
            $this->transition->execute(
                experiment: $experiment,
                toState: ExperimentStatus::BuildingFailed,
                reason: $reason,
            );
        }
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $worktree, array $args): string
    {
        $result = Process::timeout(120)->run(array_merge(['git', '-C', $worktree], $args));
        if (! $result->successful()) {
            throw new \RuntimeException('git '.($args[0] ?? '').' failed: '.trim($result->errorOutput()));
        }

        return trim($result->output());
    }

    private function scrub(string $message, ?string $cloneUrl): string
    {
        if ($cloneUrl) {
            $message = str_replace($cloneUrl, '[repo-url]', $message);
        }

        // Strip any embedded userinfo token from stray git error output.
        return (string) preg_replace('#https://[^@/\s]+:[^@/\s]+@#', 'https://[redacted]@', $message);
    }
}
