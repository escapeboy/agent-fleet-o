<?php

declare(strict_types=1);

namespace App\Domain\GitRepository\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Generates a compressed repo map (file tree + key signatures) for context injection.
 *
 * Strategy 1: Repomix CLI (if available) — rich XML output.
 * Strategy 2: In-house fallback — git ls-files tree + PHP token signatures.
 */
class RepoMapGenerator
{
    private const MAX_CHARS = 30000;

    public function generate(string $repoPath): string
    {
        // Strategy 1: Try repomix CLI
        $repomixMap = $this->tryRepomix($repoPath);
        if ($repomixMap !== null) {
            return $this->truncate($repomixMap);
        }

        // Strategy 2: In-house generator
        return $this->truncate($this->generateInHouse($repoPath));
    }

    private function tryRepomix(string $repoPath): ?string
    {
        $which = new Process(['which', 'repomix']);
        $which->run();
        if (! $which->isSuccessful()) {
            return null;
        }

        try {
            $process = new Process(
                ['repomix', '--style', 'xml', '--no-file-summary', $repoPath],
                timeout: 60,
            );
            $process->run();

            if ($process->isSuccessful() && mb_strlen($process->getOutput()) > 100) {
                return $process->getOutput();
            }
        } catch (\Throwable $e) {
            Log::debug('RepoMapGenerator: repomix failed', ['error' => $e->getMessage()]);
        }

        return null;
    }

    private function generateInHouse(string $repoPath): string
    {
        $files = $this->listGitFiles($repoPath);

        if (empty($files)) {
            return "# Repository Map\n\nNo tracked files found.";
        }

        $tree = $this->buildFileTree($files);
        $signatures = $this->extractSignatures($repoPath, $files);

        $output = "# Repository Map\n\n";
        $output .= "## File Tree\n\n```\n".$tree."\n```\n\n";

        if (! empty($signatures)) {
            $output .= "## Key Symbols\n\n".$signatures;
        }

        return $output;
    }

    /**
     * @return string[]
     */
    private function listGitFiles(string $repoPath): array
    {
        $process = new Process(['git', '-C', $repoPath, 'ls-files'], timeout: 30);
        $process->run();

        if (! $process->isSuccessful()) {
            return [];
        }

        return array_filter(explode("\n", trim($process->getOutput())));
    }

    /**
     * @param  string[]  $files
     */
    private function buildFileTree(array $files): string
    {
        $tree = [];

        foreach ($files as $file) {
            $parts = explode('/', $file);
            $current = &$tree;
            foreach ($parts as $part) {
                if (! isset($current[$part])) {
                    $current[$part] = [];
                }
                $current = &$current[$part];
            }
        }

        return $this->renderTree($tree, '');
    }

    /**
     * @param  array<string, mixed>  $tree
     */
    private function renderTree(array $tree, string $prefix): string
    {
        $lines = [];
        $keys = array_keys($tree);
        $last = end($keys);

        foreach ($keys as $name) {
            $isLast = ($name === $last);
            $connector = $isLast ? '└── ' : '├── ';
            $lines[] = $prefix.$connector.$name;

            if (! empty($tree[$name])) {
                $extension = $isLast ? '    ' : '│   ';
                $lines[] = $this->renderTree($tree[$name], $prefix.$extension);
            }
        }

        return implode("\n", array_filter($lines));
    }

    /**
     * Extract PHP class/method signatures from .php files.
     *
     * @param  string[]  $files
     */
    private function extractSignatures(string $repoPath, array $files): string
    {
        $phpFiles = array_filter($files, fn ($f) => str_ends_with($f, '.php'));
        // Limit to avoid excessive processing
        $phpFiles = array_slice($phpFiles, 0, 100);

        $parts = [];

        foreach ($phpFiles as $file) {
            $fullPath = rtrim($repoPath, '/').'/'.$file;

            if (! is_readable($fullPath)) {
                continue;
            }

            $content = @file_get_contents($fullPath);
            if ($content === false) {
                continue;
            }

            $sigs = $this->extractPhpSignatures($content);
            if (! empty($sigs)) {
                $parts[] = "### {$file}\n".$sigs;
            }
        }

        return implode("\n\n", $parts);
    }

    private function extractPhpSignatures(string $content): string
    {
        try {
            $tokens = token_get_all($content);
        } catch (\Throwable) {
            return '';
        }

        $lines = [];
        $count = count($tokens);
        $namespace = '';
        $currentClass = '';

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (! is_array($token)) {
                continue;
            }

            [$type, $value] = $token;

            if ($type === T_NAMESPACE) {
                $ns = '';
                $j = $i + 1;
                while ($j < $count) {
                    $t = $tokens[$j];
                    if (is_array($t) && in_array($t[0], [T_NAME_QUALIFIED, T_STRING, T_NS_SEPARATOR], true)) {
                        $ns .= $t[1];
                    } elseif (is_string($t) && $t === ';') {
                        break;
                    }
                    $j++;
                }
                $namespace = $ns;
            }

            if ($type === T_CLASS || $type === T_INTERFACE || $type === T_TRAIT || $type === T_ENUM) {
                // Look ahead for class name
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }
                if ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $currentClass = $tokens[$j][1];
                    $typeLabel = match ($type) {
                        T_INTERFACE => 'interface',
                        T_TRAIT => 'trait',
                        T_ENUM => 'enum',
                        default => 'class',
                    };
                    $lines[] = "{$typeLabel} {$currentClass}";
                }
            }

            if ($type === T_FUNCTION && $currentClass !== '') {
                // Build function signature
                $sig = '';
                $depth = 0;
                $j = $i - 1;
                // Walk back to pick up visibility
                while ($j >= 0) {
                    $t = $tokens[$j];
                    if (is_array($t) && in_array($t[0], [T_PUBLIC, T_PROTECTED, T_PRIVATE, T_STATIC, T_ABSTRACT, T_FINAL], true)) {
                        $sig = $t[1].' '.$sig;
                    } elseif (is_array($t) && $t[0] === T_WHITESPACE) {
                        // skip
                    } else {
                        break;
                    }
                    $j--;
                }

                $sig .= 'function ';
                $j = $i + 1;
                // Forward to closing paren
                while ($j < $count) {
                    $t = $tokens[$j];
                    $val = is_array($t) ? $t[1] : $t;
                    if ($val === '{') {
                        break;
                    }
                    $sig .= $val;
                    $j++;
                }
                $lines[] = '  '.trim($sig);
            }
        }

        return implode("\n", $lines);
    }

    private function truncate(string $output): string
    {
        if (mb_strlen($output) <= self::MAX_CHARS) {
            return $output;
        }

        return mb_substr($output, 0, self::MAX_CHARS - 100)."\n\n[... truncated — repo map exceeded 30,000 chars ...]";
    }
}
