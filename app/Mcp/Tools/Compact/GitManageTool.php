<?php

namespace App\Mcp\Tools\Compact;

use App\Mcp\Tools\GitRepository\GitBranchCreateTool;
use App\Mcp\Tools\GitRepository\GitCommitTool;
use App\Mcp\Tools\GitRepository\GitFileListTool;
use App\Mcp\Tools\GitRepository\GitFileReadTool;
use App\Mcp\Tools\GitRepository\GitFileWriteTool;
use App\Mcp\Tools\GitRepository\GitPullRequestCreateTool;
use App\Mcp\Tools\GitRepository\GitPullRequestListTool;
use App\Mcp\Tools\GitRepository\GitRepositoryCreateTool;
use App\Mcp\Tools\GitRepository\GitRepositoryDeleteTool;
use App\Mcp\Tools\GitRepository\GitRepositoryGetTool;
use App\Mcp\Tools\GitRepository\GitRepositoryListTool;
use App\Mcp\Tools\GitRepository\GitRepositoryTestTool;
use App\Mcp\Tools\GitRepository\GitRepositoryUpdateTool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[IsDestructive]
class GitManageTool extends CompactTool
{
    protected string $name = 'git_manage';

    protected string $description = <<<'TXT'
Connect, browse, and modify external git repositories (GitHub, GitLab, Bitbucket, generic SSH). Repo connections store encrypted credentials and use `phpseclib` for SSH. File and commit ops happen through the platform's `AtomicCommittingGitClient` so concurrent agent edits don't corrupt working trees.

Repo actions:
- repo_list / repo_get (read).
- repo_create (write) — name, url, credentials (object).
- repo_update (write) — repo_id + any creatable field.
- repo_delete (DESTRUCTIVE) — repo_id. Drops connection; does not delete the remote repo.
- repo_test (read) — repo_id. Verifies connectivity + auth.

File / branch / commit actions:
- file_read (read) — repo_id, path, optional branch.
- file_write (write — pushes to remote) — repo_id, path, content, branch, message.
- file_list (read) — repo_id, optional path, branch.
- branch_create (write) — repo_id, name, source branch.
- commit (write — pushes to remote) — repo_id, message, files[].
- pr_create (write) — repo_id, title, body, source branch, target branch. Returns PR URL.
- pr_list (read) — repo_id; optional state filter.
TXT;

    protected function toolMap(): array
    {
        return [
            'repo_list' => GitRepositoryListTool::class,
            'repo_get' => GitRepositoryGetTool::class,
            'repo_create' => GitRepositoryCreateTool::class,
            'repo_update' => GitRepositoryUpdateTool::class,
            'repo_delete' => GitRepositoryDeleteTool::class,
            'repo_test' => GitRepositoryTestTool::class,
            'file_read' => GitFileReadTool::class,
            'file_write' => GitFileWriteTool::class,
            'file_list' => GitFileListTool::class,
            'branch_create' => GitBranchCreateTool::class,
            'commit' => GitCommitTool::class,
            'pr_create' => GitPullRequestCreateTool::class,
            'pr_list' => GitPullRequestListTool::class,
        ];
    }
}
