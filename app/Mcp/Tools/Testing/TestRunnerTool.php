<?php

namespace App\Mcp\Tools\Testing;

use App\Domain\GitRepository\Models\GitRepository;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool for running tests in a connected git repository via bridge.
 *
 * Executes test suites (PHPUnit, Jest, pytest, etc.) in the repository
 * working directory on the connected bridge machine and returns results.
 */
class TestRunnerTool extends Tool
{
    protected string $name = 'test_run';

    protected string $description = 'Run the test suite for a repository. Supports PHPUnit, Pest, Jest, pytest, Go test, and custom commands. Returns pass/fail counts and failure details.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'framework' => $schema->string()
                ->description('Test framework: phpunit, pest, jest, pytest, go, custom')
                ->enum(['phpunit', 'pest', 'jest', 'pytest', 'go', 'custom']),
            'filter' => $schema->string()
                ->description('Run only tests matching this name filter (e.g. "UserTest" for PHPUnit, "-k test_login" for pytest)'),
            'custom_command' => $schema->string()
                ->description('Custom test command to run (used when framework=custom or to override defaults)'),
            'timeout' => $schema->integer()
                ->description('Timeout in seconds (default: 120)'),
        ];
    }

    public function handle(Request $request): Response
    {
        $repo = GitRepository::find($request->get('repository_id'));

        if (! $repo) {
            return Response::error('Repository not found.');
        }

        $framework = $request->get('framework', 'phpunit');
        $filter = $request->get('filter');
        $customCommand = $request->get('custom_command');
        $timeout = (int) ($request->get('timeout', 120));

        try {
            $command = $customCommand ?? $this->buildCommand($framework, $filter);
            $workingDir = $repo->getAttribute('local_path') ?? null;

            $result = $this->runCommand($command, $workingDir, $timeout);

            $parsed = $this->parseOutput($result['output'], $framework);

            return Response::text(json_encode([
                'success' => $result['exit_code'] === 0,
                'exit_code' => $result['exit_code'],
                'framework' => $framework,
                'command' => $command,
                'passed' => $parsed['passed'],
                'failed' => $parsed['failed'],
                'skipped' => $parsed['skipped'],
                'output' => $result['output'],
                'duration_seconds' => $result['duration'],
            ]));
        } catch (\Throwable $e) {
            return Response::error($e->getMessage());
        }
    }

    private function buildCommand(string $framework, ?string $filter): string
    {
        return match ($framework) {
            'phpunit' => 'vendor/bin/phpunit'.($filter ? ' --filter='.escapeshellarg($filter) : ''),
            'pest' => 'vendor/bin/pest'.($filter ? ' --filter='.escapeshellarg($filter) : ''),
            'jest' => 'npx jest'.($filter ? ' --testNamePattern='.escapeshellarg($filter) : ''),
            'pytest' => 'python -m pytest'.($filter ? ' -k '.escapeshellarg($filter) : ''),
            'go' => 'go test ./...'.($filter ? ' -run '.escapeshellarg($filter) : ''),
            default => throw new \InvalidArgumentException("Unknown test framework: {$framework}. Use custom_command for custom setups."),
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

                throw new \RuntimeException("Test run timed out after {$timeout}s");
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
        $duration = round(microtime(true) - $start, 2);

        return [
            'output' => trim($output),
            'exit_code' => $exitCode,
            'duration' => $duration,
        ];
    }

    private function parseOutput(string $output, string $framework): array
    {
        $passed = null;
        $failed = null;
        $skipped = null;

        if (in_array($framework, ['phpunit', 'pest'], true)) {
            // PHPUnit/Pest: "Tests: 42, Assertions: 156, Failures: 2"
            // "Tests:" is the total count; compute passed = total - failed - skipped
            $total = null;
            if (preg_match('/Tests:\s+(\d+)/', $output, $m)) {
                $total = (int) $m[1];
            }
            if (preg_match('/Failures:\s+(\d+)/', $output, $m)) {
                $failed = (int) $m[1];
            }
            if (preg_match('/Skipped:\s+(\d+)/', $output, $m)) {
                $skipped = (int) $m[1];
            }
            if ($total !== null) {
                $passed = $total - ($failed ?? 0) - ($skipped ?? 0);
            }
        } elseif ($framework === 'jest') {
            // Jest: "Tests: 2 failed, 40 passed, 42 total"
            if (preg_match('/(\d+) passed/', $output, $m)) {
                $passed = (int) $m[1];
            }
            if (preg_match('/(\d+) failed/', $output, $m)) {
                $failed = (int) $m[1];
            }
            if (preg_match('/(\d+) skipped/', $output, $m)) {
                $skipped = (int) $m[1];
            }
        } elseif ($framework === 'pytest') {
            // pytest: "5 passed, 2 failed, 1 skipped"
            if (preg_match('/(\d+) passed/', $output, $m)) {
                $passed = (int) $m[1];
            }
            if (preg_match('/(\d+) failed/', $output, $m)) {
                $failed = (int) $m[1];
            }
            if (preg_match('/(\d+) skipped/', $output, $m)) {
                $skipped = (int) $m[1];
            }
        } elseif ($framework === 'go') {
            // go test: "ok" or "FAIL"
            $passed = substr_count($output, '--- PASS');
            $failed = substr_count($output, '--- FAIL');
        }

        return [
            'passed' => $passed,
            'failed' => $failed,
            'skipped' => $skipped,
        ];
    }
}
