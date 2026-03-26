<?php

namespace App\Mcp\Tools\Testing;

use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool for running linters and code style checkers in a repository.
 *
 * Supports PHP (Pint, PHPCS, PHPStan), JavaScript (ESLint, Prettier),
 * Python (flake8, black, mypy), and custom lint commands.
 */
class LintTool extends Tool
{
    protected string $name = 'lint_run';

    protected string $description = 'Run linting and static analysis on a repository. Supports PHP Pint, PHPStan, ESLint, Prettier, flake8, black, mypy, and custom commands. Returns issues and violations.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'linter' => $schema->string()
                ->description('Linter: pint, phpstan, eslint, prettier, flake8, black, mypy, custom')
                ->enum(['pint', 'phpstan', 'eslint', 'prettier', 'flake8', 'black', 'mypy', 'custom']),
            'paths' => $schema->string()
                ->description('Paths to lint (space-separated, e.g. "src/ tests/" — defaults to project root)'),
            'fix' => $schema->boolean()
                ->description('Auto-fix violations where supported (pint, prettier, black). Default: false'),
            'custom_command' => $schema->string()
                ->description('Custom lint command to run (used when linter=custom)'),
            'timeout' => $schema->integer()
                ->description('Timeout in seconds (default: 60)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = GitRepository::find($request->get('repository_id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        $linter = $request->get('linter', 'pint');
        $paths = $request->get('paths', '');
        $fix = (bool) $request->get('fix', false);
        $customCommand = $request->get('custom_command');
        $timeout = (int) ($request->get('timeout', 60));

        try {
            $command = $customCommand ?? $this->buildCommand($linter, $paths, $fix);
            $workingDir = $repo->getAttribute('local_path') ?? null;

            $result = $this->runCommand($command, $workingDir, $timeout);
            $issues = $this->parseOutput($result['output'], $linter);

            return Response::text(json_encode([
                'success' => $result['exit_code'] === 0,
                'exit_code' => $result['exit_code'],
                'linter' => $linter,
                'command' => $command,
                'issue_count' => count($issues),
                'issues' => array_slice($issues, 0, 50), // Cap at 50 to avoid huge payloads
                'output' => $result['output'],
                'fixed' => $fix && $result['exit_code'] === 0,
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function buildCommand(string $linter, string $paths, bool $fix): string
    {
        $pathArg = $paths ? " {$paths}" : '';

        return match ($linter) {
            'pint' => 'vendor/bin/pint'.($fix ? '' : ' --test').$pathArg,
            'phpstan' => 'vendor/bin/phpstan analyse'.($pathArg ?: ' .'),
            'eslint' => 'npx eslint'.($fix ? ' --fix' : '').($pathArg ?: ' .'),
            'prettier' => 'npx prettier'.($fix ? ' --write' : ' --check').($pathArg ?: ' .'),
            'flake8' => 'python -m flake8'.($pathArg ?: ' .'),
            'black' => 'python -m black'.($fix ? '' : ' --check').($pathArg ?: ' .'),
            'mypy' => 'python -m mypy'.($pathArg ?: ' .'),
            default => throw new \InvalidArgumentException("Unknown linter: {$linter}. Use custom_command for custom setups."),
        };
    }

    private function runCommand(string $command, ?string $workingDir, int $timeout): array
    {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $cwd = $workingDir ?? sys_get_temp_dir();
        $start = microtime(true);

        $process = proc_open($command, $descriptors, $pipes, $cwd, null);

        if ($process === false) {
            throw new \RuntimeException("Failed to start process: {$command}");
        }

        fclose($pipes[0]);

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $output = '';
        $deadline = $start + $timeout;

        while (true) {
            $status = proc_get_status($process);

            if (! $status['running']) {
                break;
            }

            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                throw new \RuntimeException("Lint run timed out after {$timeout}s");
            }

            $chunk = fread($pipes[1], 4096);

            if ($chunk !== false) {
                $output .= $chunk;
            }

            usleep(100_000);
        }

        $remaining = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);

        if ($remaining !== false) {
            $output .= $remaining;
        }

        if ($stderr !== false) {
            $output .= "\n".$stderr;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);

        return [
            'output' => trim($output),
            'exit_code' => $exitCode,
        ];
    }

    /**
     * @return array<int, array{file: string|null, line: int|null, message: string}>
     */
    private function parseOutput(string $output, string $linter): array
    {
        $issues = [];
        $lines = explode("\n", $output);

        if (in_array($linter, ['pint', 'phpstan'], true)) {
            // PHPStan: "src/Foo.php:42:0: ERROR - message"
            // Pint: "  ✗ src/Foo.php" (file-level)
            foreach ($lines as $line) {
                $line = trim($line);

                if (preg_match('/^(.+\.php):(\d+):\d+:\s+(.+)$/', $line, $m)) {
                    $issues[] = ['file' => $m[1], 'line' => (int) $m[2], 'message' => $m[3]];
                } elseif (preg_match('/^✗\s+(.+)$/', $line, $m)) {
                    $issues[] = ['file' => $m[1], 'line' => null, 'message' => 'Code style violation'];
                }
            }
        } elseif ($linter === 'eslint') {
            // ESLint: "  src/foo.js  1:5  error  message  rule-name"
            foreach ($lines as $line) {
                if (preg_match('/^\s+(\d+):(\d+)\s+(error|warning)\s+(.+?)\s+\S+$/', $line, $m)) {
                    $issues[] = ['file' => null, 'line' => (int) $m[1], 'message' => "[{$m[3]}] {$m[4]}"];
                }
            }
        } elseif ($linter === 'flake8') {
            // flake8: "src/foo.py:10:5: E501 line too long"
            foreach ($lines as $line) {
                if (preg_match('/^(.+):(\d+):\d+:\s+(.+)$/', $line, $m)) {
                    $issues[] = ['file' => $m[1], 'line' => (int) $m[2], 'message' => $m[3]];
                }
            }
        } elseif ($linter === 'mypy') {
            // mypy: "src/foo.py:15: error: Incompatible types"
            foreach ($lines as $line) {
                if (preg_match('/^(.+):(\d+):\s+(error|warning|note):\s+(.+)$/', $line, $m)) {
                    $issues[] = ['file' => $m[1], 'line' => (int) $m[2], 'message' => "[{$m[3]}] {$m[4]}"];
                }
            }
        } else {
            // Generic: count non-empty output lines as issues when exit code != 0
            foreach ($lines as $line) {
                $line = trim($line);

                if ($line !== '') {
                    $issues[] = ['file' => null, 'line' => null, 'message' => $line];
                }
            }
        }

        return $issues;
    }
}
