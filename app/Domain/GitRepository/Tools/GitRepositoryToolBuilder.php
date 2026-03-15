<?php

namespace App\Domain\GitRepository\Tools;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use Prism\Prism\Facades\Tool as PrismTool;
use Prism\Prism\Tool as PrismToolObject;

/**
 * Builds a set of PrismPHP Tool objects scoped to a specific GitRepository.
 * These tools are injected into agent executions so the LLM can read,
 * write, branch, commit, and open pull requests on the repository.
 */
class GitRepositoryToolBuilder
{
    public function __construct(
        private readonly GitOperationRouter $router,
    ) {}

    /**
     * Return Prism Tool objects for the given repository.
     *
     * @return array<PrismToolObject>
     */
    public function build(GitRepository $repo): array
    {
        $slug = $repo->name;
        $client = $this->router->resolve($repo);

        return [
            PrismTool::as("git_{$slug}_read_file")
                ->for("Read a file from the '{$slug}' repository")
                ->withStringParameter('path', 'File path relative to repo root', true)
                ->withStringParameter('ref', 'Branch, tag, or commit SHA (default: HEAD)', false)
                ->using(function (string $path, string $ref = 'HEAD') use ($client): string {
                    return $client->readFile($path, $ref);
                }),

            PrismTool::as("git_{$slug}_list_files")
                ->for("List files/directories in the '{$slug}' repository")
                ->withStringParameter('path', 'Directory path (default: /)', false)
                ->withStringParameter('ref', 'Branch, tag, or commit SHA (default: HEAD)', false)
                ->using(function (string $path = '/', string $ref = 'HEAD') use ($client): string {
                    $files = $client->listFiles($path, $ref);

                    return json_encode($files, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
                }),

            PrismTool::as("git_{$slug}_write_file")
                ->for("Write a file and commit it to the '{$slug}' repository")
                ->withStringParameter('path', 'File path relative to repo root', true)
                ->withStringParameter('content', 'New file content', true)
                ->withStringParameter('message', 'Commit message', true)
                ->withStringParameter('branch', 'Target branch name', true)
                ->using(function (string $path, string $content, string $message, string $branch) use ($client): string {
                    $sha = $client->writeFile($path, $content, $message, $branch);

                    return json_encode(['commit_sha' => $sha, 'status' => 'committed']);
                }),

            PrismTool::as("git_{$slug}_create_branch")
                ->for("Create a new branch in the '{$slug}' repository")
                ->withStringParameter('branch', 'New branch name', true)
                ->withStringParameter('from', 'Source branch or SHA to branch from', true)
                ->using(function (string $branch, string $from) use ($client): string {
                    $client->createBranch($branch, $from);

                    return json_encode(['branch' => $branch, 'status' => 'created']);
                }),

            PrismTool::as("git_{$slug}_commit")
                ->for("Commit multiple file changes atomically to the '{$slug}' repository")
                ->withStringParameter('changes_json', 'JSON array of {path, content} objects (set content to null to delete)', true)
                ->withStringParameter('message', 'Commit message', true)
                ->withStringParameter('branch', 'Target branch name', true)
                ->using(function (string $changes_json, string $message, string $branch) use ($client): string {
                    $changes = json_decode($changes_json, true) ?? [];
                    $sha = $client->commit($changes, $message, $branch);

                    return json_encode(['commit_sha' => $sha, 'status' => 'committed']);
                }),

            PrismTool::as("git_{$slug}_create_pr")
                ->for("Open a pull request on the '{$slug}' repository")
                ->withStringParameter('title', 'Pull request title', true)
                ->withStringParameter('body', 'Pull request body / description', true)
                ->withStringParameter('head', 'Source branch (feature branch)', true)
                ->withStringParameter('base', 'Target branch to merge into (e.g. main)', true)
                ->using(function (string $title, string $body, string $head, string $base) use ($client): string {
                    $pr = $client->createPullRequest($title, $body, $head, $base);

                    return json_encode($pr);
                }),
        ];
    }
}
