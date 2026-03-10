<?php

namespace App\Domain\Agent\Services;

/**
 * Per-execution filesystem sandbox.
 *
 * Creates an isolated directory tree under storage/app/sandboxes/{teamId}/{agentId}/{executionId}/.
 * Agent-supplied paths are resolved through resolve() which rejects path traversal attempts.
 * teardown() recursively removes the sandbox directory — call it in a finally block.
 */
final class SandboxedWorkspace
{
    private readonly string $rootPath;

    public function __construct(
        private readonly string $executionId,
        string $agentId,
        string $teamId,
        ?string $basePath = null,
    ) {
        $base = rtrim($basePath ?? storage_path('app/sandboxes'), DIRECTORY_SEPARATOR);
        $this->rootPath = implode(DIRECTORY_SEPARATOR, [
            $base,
            $teamId,
            $agentId,
            $executionId,
        ]);

        if (! is_dir($this->rootPath)) {
            mkdir($this->rootPath.'/uploads', 0700, true);
            mkdir($this->rootPath.'/outputs', 0700);
            mkdir($this->rootPath.'/tmp', 0700);
        }
    }

    /**
     * Resolve a virtual path to an absolute sandboxed path.
     *
     * @throws \OutOfBoundsException on path traversal attempt
     */
    public function resolve(string $virtualPath): string
    {
        $relative = ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $virtualPath), DIRECTORY_SEPARATOR);
        $normalized = $this->normalizePath($this->rootPath.DIRECTORY_SEPARATOR.$relative);

        $rootWithSep = $this->rootPath.DIRECTORY_SEPARATOR;

        if ($normalized !== $this->rootPath && ! str_starts_with($normalized, $rootWithSep)) {
            throw new \OutOfBoundsException(
                "Path traversal detected: '{$virtualPath}' escapes sandbox boundary."
            );
        }

        return $normalized;
    }

    public function root(): string
    {
        return $this->rootPath;
    }

    public function uploadsDir(): string
    {
        return $this->rootPath.'/uploads';
    }

    public function outputsDir(): string
    {
        return $this->rootPath.'/outputs';
    }

    public function tmpDir(): string
    {
        return $this->rootPath.'/tmp';
    }

    public function executionId(): string
    {
        return $this->executionId;
    }

    public function teardown(): void
    {
        if (is_dir($this->rootPath)) {
            $this->deleteDirectory($this->rootPath);
        }
    }

    private function normalizePath(string $path): string
    {
        $sep = DIRECTORY_SEPARATOR;
        $parts = explode($sep, str_replace(['/', '\\'], $sep, $path));
        $normalized = [];

        foreach ($parts as $part) {
            if ($part === '' || $part === '.') {
                continue;
            }
            if ($part === '..') {
                array_pop($normalized);
            } else {
                $normalized[] = $part;
            }
        }

        return $sep.implode($sep, $normalized);
    }

    private function deleteDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}
