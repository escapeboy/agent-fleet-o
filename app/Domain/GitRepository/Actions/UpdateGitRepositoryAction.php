<?php

namespace App\Domain\GitRepository\Actions;

use App\Domain\GitRepository\Enums\GitProvider;
use App\Domain\GitRepository\Enums\GitRepoMode;
use App\Domain\GitRepository\Models\GitRepository;

class UpdateGitRepositoryAction
{
    public function execute(
        GitRepository $repo,
        ?string $name = null,
        ?string $url = null,
        ?GitRepoMode $mode = null,
        ?GitProvider $provider = null,
        ?string $defaultBranch = null,
        ?string $credentialId = null,
        ?array $config = null,
    ): GitRepository {
        $updates = array_filter([
            'name' => $name,
            'url' => $url,
            'mode' => $mode,
            'provider' => $provider,
            'default_branch' => $defaultBranch,
            'credential_id' => $credentialId,
            'config' => $config,
        ], fn ($v) => $v !== null);

        // If URL changed and provider not explicitly set, auto-detect
        if (isset($updates['url']) && ! isset($updates['provider'])) {
            $updates['provider'] = GitProvider::detectFromUrl($updates['url']);
        }

        $repo->update($updates);

        return $repo->fresh();
    }
}
