<?php

namespace App\Domain\Agent\Services;

use RuntimeException;

/**
 * Protects the host filesystem and validates sandbox output.
 *
 * - validatePath(): blocks path traversal and writes to sensitive files
 * - scanOutput(): scans stdout/stderr for accidental secret leakage
 */
class FilesystemGuard
{
    /** Path substrings that are never safe to write to */
    private const BLOCKED_PATH_FRAGMENTS = [
        '.env',
        '.git/hooks',
        'authorized_keys',
        '.ssh/',
        '.bashrc',
        '.bash_profile',
        '.zshrc',
        '.profile',
    ];

    /** Patterns indicating a potential secret in output text */
    private const SECRET_PATTERNS = [
        '/-----BEGIN (RSA |EC |OPENSSH )?PRIVATE KEY-----/',
        '/password\s*=/i',
        '/secret\s*=/i',
        '/AKIA[A-Z0-9]{16}/',                // AWS access key ID
        '/AIza[0-9A-Za-z_\-]{35}/',          // GCP API key
        '/sk-[a-zA-Z0-9]{48}/',              // OpenAI key prefix
        '/xox[baprs]-[0-9a-zA-Z\-]{10,}/',  // Slack token
        '/ghp_[0-9a-zA-Z]{36}/',             // GitHub PAT
    ];

    /**
     * Assert that a requested path is safe to write to within the given base path.
     *
     * @throws RuntimeException on violation
     */
    public function validatePath(string $path, string $basePath): void
    {
        $resolvedBase = realpath($basePath);
        $resolvedPath = realpath($path) ?: $path;

        // Block path traversal attempts
        if ($resolvedBase && ! str_starts_with($resolvedPath, $resolvedBase.DIRECTORY_SEPARATOR)) {
            throw new RuntimeException("Path traversal detected: {$path}");
        }

        // Block sensitive path fragments
        $relative = str_replace([$basePath.DIRECTORY_SEPARATOR, $basePath.'/'], '', $path);
        foreach (self::BLOCKED_PATH_FRAGMENTS as $fragment) {
            if (str_contains($relative, $fragment)) {
                throw new RuntimeException("Write to sensitive path blocked: {$relative}");
            }
        }
    }

    /**
     * Scan the sandbox output for patterns suggesting accidental secret leakage.
     *
     * @return string[] List of violation descriptions (empty = clean)
     */
    public function scanOutput(string $output): array
    {
        $violations = [];

        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $output)) {
                $violations[] = 'Potential secret in output (pattern: '.$pattern.')';
            }
        }

        return $violations;
    }
}
