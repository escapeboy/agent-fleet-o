<?php

namespace App\Domain\GitRepository\Actions;

use App\Domain\GitRepository\Models\GitRepository;

class DeleteGitRepositoryAction
{
    public function execute(GitRepository $repo): void
    {
        $repo->delete();
    }
}
