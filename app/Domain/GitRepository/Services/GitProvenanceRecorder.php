<?php

namespace App\Domain\GitRepository\Services;

use App\Domain\GitRepository\Models\GitCommitProvenance;

class GitProvenanceRecorder
{
    public function __construct(private readonly GitProvenanceContext $context) {}

    public static function enabled(): bool
    {
        return (bool) config('git_repository.provenance.enabled', false);
    }

    /**
     * Persist a provenance row for a commit. Never throws: a recording failure
     * must not break the commit that already happened.
     *
     * @param  array<string, string>  $trailers
     */
    public function record(?string $teamId, ?string $repositoryId, string $sha, ?string $branch, array $trailers): void
    {
        try {
            if ($teamId === null || $teamId === '' || $sha === '') {
                return;
            }

            GitCommitProvenance::create([
                'team_id' => $teamId,
                'git_repository_id' => $repositoryId,
                'commit_sha' => $sha,
                'branch' => $branch,
                'source' => $this->context->source,
                'experiment_id' => $this->context->experimentId,
                'agent_id' => $this->context->agentId,
                'skill_execution_id' => $this->context->skillExecutionId,
                'trailers' => $trailers === [] ? null : $trailers,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
