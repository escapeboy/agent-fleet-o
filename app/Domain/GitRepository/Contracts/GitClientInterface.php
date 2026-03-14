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
}
