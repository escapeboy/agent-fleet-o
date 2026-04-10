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

    protected string $description = 'Manage git repositories and operations. Actions: repo_list, repo_get (repo_id), repo_create (name, url, credentials), repo_update (repo_id + fields), repo_delete (repo_id), repo_test (repo_id — test connection), file_read (repo_id, path, branch), file_write (repo_id, path, content, branch, message), file_list (repo_id, path, branch), branch_create (repo_id, name, source), commit (repo_id, message, files), pr_create (repo_id, title, body, source, target), pr_list (repo_id).';

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
