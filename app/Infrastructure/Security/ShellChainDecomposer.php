<?php

namespace App\Infrastructure\Security;

/**
 * Detects and decomposes shell chain operators in user-supplied strings.
 *
 * Prevents SSRF guard bypass via chained commands like:
 *   http://safe-host.com; http://internal/
 *   http://safe.com && curl http://evil.com
 *
 * Only unquoted operators are considered — quoted segments are stripped
 * before matching to avoid false positives on legitimate data.
 */
class ShellChainDecomposer
{
    /**
     * Chain operator patterns that join separate shell commands.
     * Semicolon is only flagged when followed by a space ("; ") to avoid
     * matching legitimate URL path params (e.g. ?a=1;b=2).
     *
     * Additional shell expansion operators (backtick, $(), newlines) are
     * also rejected since they are never valid in webhook URL context.
     */
    private const CHAIN_PATTERNS = [
        '&&',
        '||',
        '; ',
        ' | ',
        '`',
        '$(',
        "\n",
        "\r",
    ];

    /**
     * Detect whether the input contains unquoted shell chain operators.
     */
    public function containsChain(string $input): bool
    {
        $unquoted = $this->stripQuotedSegments($input);

        foreach (self::CHAIN_PATTERNS as $pattern) {
            if (str_contains($unquoted, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Split the input on chain operators into individual segments.
     *
     * @return string[]
     */
    public function decompose(string $input): array
    {
        $segments = [$input];

        foreach (self::CHAIN_PATTERNS as $operator) {
            $next = [];
            foreach ($segments as $segment) {
                foreach (explode($operator, $segment) as $part) {
                    $trimmed = trim($part);
                    if ($trimmed !== '') {
                        $next[] = $trimmed;
                    }
                }
            }
            $segments = $next;
        }

        return $segments;
    }

    /**
     * Strip shell metacharacters from a string for safe inclusion in log messages.
     */
    public function sanitizeForLog(string $input): string
    {
        return str_replace(['`', '$', '\\', '|', ';', '&', '>', '<', '!', '(', ')'], '', $input);
    }

    /**
     * Replace single- and double-quoted substrings with empty placeholders
     * so operators inside quotes are not flagged.
     */
    private function stripQuotedSegments(string $input): string
    {
        $output = preg_replace('/"[^"]*"/', '""', $input) ?? $input;

        return preg_replace("/'[^']*'/", "''", $output) ?? $output;
    }
}
