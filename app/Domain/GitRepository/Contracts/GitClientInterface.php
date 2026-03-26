<?php

namespace App\Domain\GitRepository\Contracts;

interface GitClientInterface
{
    /**
     * Check if the repository is reachable with the provided credentials.
     */
    public function ping(): bool;

    /**
     * Read the content of a file at the given path and ref.
     */
    public function readFile(string $path, string $ref = 'HEAD'): string;

    /**
     * Write a single file and commit the change in one operation.
     *
     * @return string Commit SHA
     */
    public function writeFile(string $path, string $content, string $message, string $branch): string;

    /**
     * List files and directories at the given path.
     *
     * @return array<array{name: string, path: string, type: string, size: int|null}>
     */
    public function listFiles(string $path = '/', string $ref = 'HEAD'): array;

    /**
     * Get the full file tree of the repository.
     *
     * @return array<array{path: string, type: string, sha: string|null}>
     */
    public function getFileTree(string $ref = 'HEAD'): array;

    /**
     * Create a new branch from an existing ref.
     */
    public function createBranch(string $branch, string $from): void;

    /**
     * Commit multiple file changes atomically.
     *
     * @param  array<array{path: string, content: string, deleted?: bool}>  $changes
     * @return string Commit SHA
     */
    public function commit(array $changes, string $message, string $branch): string;

    /**
     * Push a local branch to the remote. (Only meaningful for sandbox/bridge modes.)
     */
    public function push(string $branch): void;

    /**
     * Open a pull request.
     *
     * @return array{pr_number: string, pr_url: string, title: string, status: string}
     */
    public function createPullRequest(string $title, string $body, string $head, string $base): array;

    /**
     * List pull requests.
     *
     * @return array<array{pr_number: string, pr_url: string, title: string, status: string, author: string|null, created_at: string}>
     */
    public function listPullRequests(string $state = 'open'): array;

    /**
     * Merge a pull request.
     *
     * @return array{sha: string, merged: bool, message: string}
     */
    public function mergePullRequest(int $prNumber, string $method = 'squash', ?string $commitTitle = null, ?string $commitMessage = null): array;

    /**
     * Get the status of a pull request (CI checks + review state).
     *
     * @return array{mergeable: bool|null, ci_passing: bool, reviews_approved: bool, checks: array<array{name: string, status: string, conclusion: string|null}>, state: string}
     */
    public function getPullRequestStatus(int $prNumber): array;

    /**
     * Dispatch a workflow (e.g. GitHub Actions) by workflow file name or ID.
     *
     * @param  array<string, string>  $inputs
     * @return array{dispatched: bool}
     */
    public function dispatchWorkflow(string $workflowId, string $ref = 'main', array $inputs = []): array;

    /**
     * Create a release with a tag and release notes.
     *
     * @return array{id: int|string, tag_name: string, name: string, url: string, draft: bool, prerelease: bool}
     */
    public function createRelease(string $tagName, string $name, string $body, string $targetCommitish = 'main', bool $draft = false, bool $prerelease = false): array;

    /**
     * Close (abandon) a pull request without merging.
     */
    public function closePullRequest(int $prNumber): void;

    /**
     * Get commit log between two refs.
     *
     * @return array<int, array{sha: string, message: string, author: string, date: string}>
     */
    public function getCommitLog(?string $fromRef = null, string $toRef = 'HEAD', int $limit = 100): array;
}
