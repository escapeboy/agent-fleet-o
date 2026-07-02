<?php

namespace App\Domain\Experiment\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitCloneUrlResolver;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Domain\GitRepository\Services\WarmRepoManager;
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
        $worktree = null;
        $prUrls = [];

        // Build phase: any failure here flips the experiment to BuildingFailed.
        // Completion (the AwaitingApproval transition) is deliberately AFTER this
        // block so a throwing downstream transition listener can't be mistaken
        // for a build failure and re-transition an already-completed experiment.
        try {
            $worktree = $this->warmRepo->checkout($repo, $ref, $experiment->id, $cloneUrl);

            $baseSha = $this->git($worktree, ['rev-parse', 'HEAD']);

            // Resolve by class (not constructor DI): the cloud edition binds
            // LocalAgentGateway::class to DisabledLocalAgentGateway (implements
            // AiGatewayInterface, not a subclass — DI-hinting the concrete class
            // would TypeError). Disabled delegates vps_only providers to a real
            // gateway, so calling complete() directly preserves workingDirectory.
            app(LocalAgentGateway::class)->complete($this->buildRequest($experiment, $worktree));

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

            if ($this->git($worktree, ['rev-parse', 'HEAD']) === $baseSha) {
                $this->fail($experiment, 'Agent produced no changes — nothing to open a PR for.');

                return;
            }

            $branch = 'fleetq/fix-'.substr($experiment->id, 0, 8);
            $this->git($worktree, ['push', 'origin', 'HEAD:refs/heads/'.$branch, '--force']);

            $pr = $this->gitRouter->resolve($repo)->createPullRequest(
                title: '[FleetQ] Fix: '.$experiment->title,
                body: $this->prBody($experiment),
                head: $branch,
                base: (string) ($repo->default_branch ?: 'main'),
                draft: true,
            );
            $prUrls = array_filter([$pr['pr_url']]);
        } catch (\Throwable $e) {
            // Transient capacity (VPS concurrency cap): the slot is acquired before
            // any agent work, so nothing was spent — surface it so the job can
            // re-dispatch after a backoff rather than failing the run.
            if ($e instanceof VpsLocalAgentException && $e->retryable) {
                throw $e;
            }

            // Never leak an authenticated clone URL into the failure reason/logs.
            $this->fail($experiment, 'Warm build failed: '.$this->scrub($e->getMessage(), $cloneUrl));

            return;
        } finally {
            if ($worktree !== null) {
                $this->warmRepo->release($repo, $worktree);
                $this->warmRepo->prune($repo);
            }
        }

        $this->completeBuilding->execute(
            experiment: $experiment,
            prUrls: $prUrls,
            summary: 'Warm-build agent opened a draft PR on '.$repo->name.'.',
            completedBy: 'agent_warm_build',
        );

        Log::info('ExecuteWarmDebugBuildAction: draft PR opened', [
            'experiment_id' => $experiment->id,
            'repo_id' => $repo->id,
            'pr_urls' => $prUrls,
        ]);
    }

    /**
     * Terminal failure for the case where the VPS concurrency cap never cleared
     * within the retry budget (called by RunWarmDebugBuildJob).
     */
    public function failCapacityExhausted(Experiment $experiment): void
    {
        $this->fail($experiment, 'Warm build deferred: VPS capacity unavailable after repeated retries.');
    }

    private function resolveRepository(Experiment $experiment): ?GitRepository
    {
        $repoId = $experiment->constraints['git_repository_id'] ?? null;

        if (! $repoId && $experiment->agent_id) {
            $agent = Agent::withoutGlobalScopes()->find($experiment->agent_id);
            $repoId = $agent?->config['git_repository_ids'][0] ?? null;
        }

        if (! $repoId) {
            return null;
        }

        return GitRepository::withoutGlobalScopes()
            ->where('team_id', $experiment->team_id)
            ->find($repoId);
    }

    private function buildRequest(Experiment $experiment, string $worktree): AiRequestDTO
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
        );
    }

    private function prBody(Experiment $experiment): string
    {
        return "Automated fix opened by FleetQ for experiment `{$experiment->id}`.\n\n"
            ."**Issue:** {$experiment->title}\n\n"
            .'⚠️ Draft PR — requires human review before merge.';
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
