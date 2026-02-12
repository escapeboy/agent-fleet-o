<?php

/**
 * Agent Fleet — Host Agent Bridge
 *
 * Lightweight HTTP bridge that runs on the host machine and proxies
 * agent discovery + execution requests from Docker containers.
 *
 * Usage:
 *   LOCAL_AGENT_BRIDGE_SECRET=your-secret php -S 0.0.0.0:8065 docker/host-bridge.php
 *
 * Endpoints:
 *   GET  /health   — liveness check (no auth)
 *   GET  /discover — list available agents (auth required)
 *   POST /execute  — run an agent (auth required)
 */

// Agent execution can take minutes — disable PHP's execution time limit
set_time_limit(0);

// ---------------------------------------------------------------------------
// Laravel polyfills — config/local_agents.php uses env()
// ---------------------------------------------------------------------------

if (! function_exists('env')) {
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        // Cast common string booleans/nulls
        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => $value,
        };
    }
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$bridgeSecret = getenv('LOCAL_AGENT_BRIDGE_SECRET') ?: '';
$configPath   = __DIR__ . '/../config/local_agents.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function json_response(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
}

function authenticate(string $secret): bool
{
    if (empty($secret)) {
        json_response(['error' => 'Bridge secret not configured on host'], 500);
        return false;
    }

    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

    if (! preg_match('/^Bearer\s+(.+)$/i', $header, $m) || ! hash_equals($secret, $m[1])) {
        json_response(['error' => 'Unauthorized'], 401);
        return false;
    }

    return true;
}

function which(string $binary): ?string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open("which " . escapeshellarg($binary), $descriptors, $pipes);

    if (! is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    $stdout = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    return ($exitCode === 0 && $stdout !== '') ? $stdout : null;
}

function get_version(string $command): ?string
{
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptors, $pipes);

    if (! is_resource($process)) {
        return null;
    }

    fclose($pipes[0]);
    $stdout = trim(stream_get_contents($pipes[1]));
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $stdout === '') {
        return null;
    }

    // Parse common version patterns
    if (preg_match('/v?(\d+\.\d+(?:\.\d+)?(?:[.\-]\w+)?)/', $stdout, $matches)) {
        return $matches[1];
    }

    return strtok($stdout, "\n") ?: $stdout;
}

function build_command(string $agentKey, string $binaryPath): ?string
{
    return match ($agentKey) {
        'codex'      => escapeshellarg($binaryPath) . ' exec --json --full-auto',
        'claude-code' => escapeshellarg($binaryPath) . ' --print --output-format json --dangerously-skip-permissions',
        default      => null,
    };
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$path   = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// --- GET /health (no auth) ------------------------------------------------
if ($method === 'GET' && $path === '/health') {
    json_response([
        'status'      => 'ok',
        'php_version' => PHP_VERSION,
        'pid'         => getmypid(),
    ]);
    return true;
}

// --- GET /discover (auth required) ----------------------------------------
if ($method === 'GET' && $path === '/discover') {
    if (! authenticate($bridgeSecret)) {
        return true;
    }

    if (! file_exists($configPath)) {
        json_response(['error' => 'local_agents.php config not found'], 500);
        return true;
    }

    $config = require $configPath;
    $agents = $config['agents'] ?? [];
    $detected = [];

    foreach ($agents as $key => $agentConfig) {
        $binary = $agentConfig['binary'] ?? null;

        if (! $binary) {
            continue;
        }

        $path_found = which($binary);

        if (! $path_found) {
            continue;
        }

        $version = 'unknown';
        if (! empty($agentConfig['detect_command'])) {
            $version = get_version($agentConfig['detect_command']) ?? 'unknown';
        }

        $detected[$key] = [
            'name'    => $agentConfig['name'] ?? $key,
            'version' => $version,
            'path'    => $path_found,
        ];
    }

    json_response(['agents' => $detected]);
    return true;
}

// --- POST /execute (auth required) ----------------------------------------
if ($method === 'POST' && $path === '/execute') {
    if (! authenticate($bridgeSecret)) {
        return true;
    }

    $body = json_decode(file_get_contents('php://input'), true);

    if (! $body || empty($body['agent_key']) || ! isset($body['prompt'])) {
        json_response(['error' => 'Missing agent_key or prompt'], 400);
        return true;
    }

    $agentKey = $body['agent_key'];
    $prompt   = $body['prompt'];
    $timeout  = (int) ($body['timeout'] ?? 300);
    $workdir  = $body['working_directory'] ?? null;

    // Validate agent_key against config
    if (! file_exists($configPath)) {
        json_response(['error' => 'local_agents.php config not found'], 500);
        return true;
    }

    $config = require $configPath;
    $agents = $config['agents'] ?? [];

    if (! isset($agents[$agentKey])) {
        json_response(['error' => "Unknown agent: {$agentKey}"], 400);
        return true;
    }

    $agentConfig = $agents[$agentKey];
    $binaryPath = which($agentConfig['binary']);

    if (! $binaryPath) {
        json_response([
            'success'   => false,
            'error'     => "Agent binary '{$agentConfig['binary']}' not found on host",
            'exit_code' => -1,
        ], 404);
        return true;
    }

    $command = build_command($agentKey, $binaryPath);

    if (! $command) {
        json_response([
            'success'   => false,
            'error'     => "No command template for agent: {$agentKey}",
            'exit_code' => -1,
        ], 400);
        return true;
    }

    // Resolve working directory
    $cwd = $workdir ?: dirname(__DIR__);
    if (! is_dir($cwd)) {
        $cwd = dirname(__DIR__);
    }

    // Execute via proc_open with stdin for prompt (no shell interpolation)
    $descriptors = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];

    $startTime = hrtime(true);

    $process = proc_open($command, $descriptors, $pipes, $cwd);

    if (! is_resource($process)) {
        json_response([
            'success'   => false,
            'error'     => 'Failed to spawn process',
            'exit_code' => -1,
        ], 500);
        return true;
    }

    // Write prompt to stdin then close
    fwrite($pipes[0], $prompt);
    fclose($pipes[0]);

    // Set non-blocking and read with timeout
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $deadline = time() + $timeout;

    while (true) {
        $status = proc_get_status($process);

        $chunk1 = stream_get_contents($pipes[1]);
        $chunk2 = stream_get_contents($pipes[2]);

        if ($chunk1 !== false) {
            $stdout .= $chunk1;
        }
        if ($chunk2 !== false) {
            $stderr .= $chunk2;
        }

        if (! $status['running']) {
            break;
        }

        if (time() > $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            json_response([
                'success'           => false,
                'error'             => "Process timed out after {$timeout}s",
                'exit_code'         => -1,
                'execution_time_ms' => $elapsedMs,
            ], 504);
            return true;
        }

        usleep(50_000); // 50ms poll
    }

    // Read any remaining output
    $chunk1 = stream_get_contents($pipes[1]);
    $chunk2 = stream_get_contents($pipes[2]);
    if ($chunk1 !== false) {
        $stdout .= $chunk1;
    }
    if ($chunk2 !== false) {
        $stderr .= $chunk2;
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

    if ($exitCode !== 0) {
        json_response([
            'success'           => false,
            'output'            => $stdout,
            'stderr'            => $stderr,
            'error'             => $stderr ?: 'Process exited with non-zero code',
            'exit_code'         => $exitCode,
            'execution_time_ms' => $elapsedMs,
        ]);
        return true;
    }

    json_response([
        'success'           => true,
        'output'            => $stdout,
        'stderr'            => $stderr,
        'exit_code'         => 0,
        'execution_time_ms' => $elapsedMs,
    ]);
    return true;
}

// --- 404 fallback ---------------------------------------------------------
json_response(['error' => 'Not found'], 404);
return true;
