<?php

namespace App\Domain\Signal\Services;

class StackTraceParser
{
    /**
     * Extract stack frames from a JavaScript error string.
     *
     * Handles formats:
     *   at functionName (file.js:line:col)
     *   at file.js:line:col
     *   at functionName (https://host/file.js:line:col)
     *
     * @return array<int, array{file: string, line: int, column: int, function: string|null}>
     */
    public function parseFrames(string $stack): array
    {
        $frames = [];
        $lines = preg_split('/\r?\n/', $stack);

        foreach ($lines as $line) {
            $line = trim($line);

            // at functionName (file.js:line:col) OR at functionName (https://host/path:line:col)
            if (preg_match('/^\s*at\s+(.+?)\s+\((.+):(\d+):(\d+)\)\s*$/', $line, $m)) {
                $frames[] = [
                    'file' => $this->normalizeFile($m[2]),
                    'line' => (int) $m[3],
                    'column' => (int) $m[4],
                    'function' => $m[1] !== '<anonymous>' ? $m[1] : null,
                ];
                continue;
            }

            // at file.js:line:col (anonymous)
            if (preg_match('/^\s*at\s+(.+):(\d+):(\d+)\s*$/', $line, $m)) {
                $frames[] = [
                    'file' => $this->normalizeFile($m[1]),
                    'line' => (int) $m[2],
                    'column' => (int) $m[3],
                    'function' => null,
                ];
            }
        }

        return $frames;
    }

    /**
     * Extract error objects from console log entries.
     *
     * @param  array<int, mixed>  $consoleLog
     * @return array<int, array{type: string, message: string, raw_stack: string, frames: array}>
     */
    public function extractErrors(array $consoleLog): array
    {
        $errors = [];

        foreach ($consoleLog as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $level = $entry['level'] ?? '';
            if (! in_array($level, ['error', 'exception'], true)) {
                continue;
            }

            $message = $entry['message'] ?? '';
            if (empty($message)) {
                continue;
            }

            // Split first line (error message) from stack
            $parts = preg_split('/\r?\n/', $message, 2);
            $header = $parts[0] ?? $message;
            $stack = $parts[1] ?? $message;

            // Parse error type from "TypeError: ..." or "Error: ..."
            $type = 'Error';
            $errorMessage = $header;
            if (preg_match('/^(\w+Error):\s*(.+)$/i', $header, $m)) {
                $type = $m[1];
                $errorMessage = $m[2];
            }

            $errors[] = [
                'type' => $type,
                'message' => $errorMessage,
                'raw_stack' => $message,
                'frames' => $this->parseFrames($stack),
            ];
        }

        return $errors;
    }

    public function isProjectFrame(array $frame): bool
    {
        $file = $frame['file'] ?? '';

        return ! str_contains($file, 'node_modules/') && ! str_starts_with($file, 'http');
    }

    private function normalizeFile(string $file): string
    {
        // Strip protocol + host from full URLs, keep path only
        if (preg_match('/^https?:\/\/[^\/]+\/(.+)$/', $file, $m)) {
            return $m[1];
        }

        return $file;
    }
}
