<?php

namespace App\Domain\GitRepository\Actions;

use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Enums\GitRepositoryStatus;
use App\Domain\GitRepository\Models\GitRepository;

class CreateGitRepositoryAction
{
    public function execute(
        string $teamId,
        string $name,
        string $url,
        GitRepoMode $mode = GitRepoMode::ApiOnly,
        ?GitProvider $provider = null,
        string $defaultBranch = 'main',
        ?string $credentialId = null,
        array $config = [],
    ): GitRepository {
        $provider ??= GitProvider::detectFromUrl($url);

        return GitRepository::create([
            'team_id' => $teamId,
            'credential_id' => $credentialId,
            'name' => $name,
            'url' => $url,
            'provider' => $provider,
            'mode' => $mode,
            'default_branch' => $defaultBranch,
            'config' => $config,
            'status' => GitRepositoryStatus::Active,
        ]);
    }
}
