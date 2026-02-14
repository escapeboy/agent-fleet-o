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
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

// ---------------------------------------------------------------------------
// Configuration
// ---------------------------------------------------------------------------

$bridgeSecret = getenv('LOCAL_AGENT_BRIDGE_SECRET') ?: '';
$configPath = __DIR__.'/../config/local_agents.php';

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function json_response(array $data, int $status = 200): void
{
    $body = json_encode($data, JSON_UNESCAPED_SLASHES);

    http_response_code($status);
    header('Content-Type: application/json');
    header('Content-Length: '.strlen($body));
    header('Connection: close');
    echo $body;

    // Flush output buffers to ensure response is fully sent before script exits.
    // Without this, PHP's built-in server may not deliver the body immediately.
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        if (ob_get_level() > 0) {
            ob_end_flush();
        }
        flush();
    }
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

    $process = proc_open('which '.escapeshellarg($binary), $descriptors, $pipes);

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

function build_command(string $agentKey, string $binaryPath, bool $streaming = false): ?string
{
    $bin = escapeshellarg($binaryPath);

    // Close inherited file descriptors (3-20) before exec.
    // PHP's proc_open passes 0/1/2 via $descriptors but leaves other FDs
    // open (including the bridge's listening socket on port 8065).
    // Without this, the child process inherits the socket and keeps it
    // alive even after the bridge tries to shut it down.
    $closeInherited = 'for fd in $(seq 3 20); do eval "exec ${fd}<&-" 2>/dev/null; done;';

    // Unset CLAUDECODE env var to prevent "nested session" detection.
    // The bridge may have been started from within a Claude Code session,
    // and the child process would inherit CLAUDECODE=1, causing Claude Code
    // to refuse to start.
    $cleanEnv = 'unset CLAUDECODE;';

    $preamble = $closeInherited.' '.$cleanEnv.' ';

    // Use stream-json when streaming for real-time output, json otherwise.
    // stream-json emits NDJSON events (assistant, content_block_delta, result)
    // that the bridge forwards and the gateway extracts text from.
    // Requires --include-partial-messages for incremental text deltas.
    // Since Claude Code 2.1+, --output-format=stream-json also requires --verbose.
    $ccOutputFormat = $streaming ? 'stream-json' : 'json';
    $ccStreamFlags = $streaming ? ' --include-partial-messages --verbose' : '';

    return match ($agentKey) {
        'codex' => $preamble.$bin.' exec --json --full-auto',
        'claude-code' => $preamble.$bin." --print --output-format {$ccOutputFormat}{$ccStreamFlags} --dangerously-skip-permissions --no-session-persistence --strict-mcp-config --mcp-config '{\"mcpServers\":{}}' --max-budget-usd 2.0",
        default => null,
    };
}

/**
 * Send an NDJSON event line and flush immediately.
 */
function stream_event(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_SLASHES)."\n";
    flush();
}

// ---------------------------------------------------------------------------
// Router
// ---------------------------------------------------------------------------

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// --- GET /health (no auth) ------------------------------------------------
if ($method === 'GET' && $path === '/health') {
    json_response([
        'status' => 'ok',
        'php_version' => PHP_VERSION,
        'pid' => getmypid(),
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
            'name' => $agentConfig['name'] ?? $key,
            'version' => $version,
            'path' => $path_found,
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
    $prompt = $body['prompt'];
    $timeout = (int) ($body['timeout'] ?? 300);
    $workdir = $body['working_directory'] ?? null;
    $streaming = (bool) ($body['stream'] ?? false);

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
            'success' => false,
            'error' => "Agent binary '{$agentConfig['binary']}' not found on host",
            'exit_code' => -1,
        ], 404);

        return true;
    }

    $command = build_command($agentKey, $binaryPath, $streaming);

    if (! $command) {
        json_response([
            'success' => false,
            'error' => "No command template for agent: {$agentKey}",
            'exit_code' => -1,
        ], 400);

        return true;
    }

    error_log("Bridge: executing [{$agentKey}] streaming=".($streaming ? 'yes' : 'no'));
    error_log("Bridge: command = {$command}");

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
        if ($streaming) {
            header('Content-Type: application/x-ndjson');
            stream_event(['type' => 'error', 'error' => 'Failed to spawn process']);

            return true;
        }
        json_response([
            'success' => false,
            'error' => 'Failed to spawn process',
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
    $lastDataTime = time();       // When data was last received from stdout
    $hasReceivedData = false;     // Whether ANY output was received yet
    $inactivityLimit = 15;        // Kill process after 15s of no new output (once data was received)

    // ─── STREAMING MODE ──────────────────────────────────────────────
    // Send NDJSON events as stdout lines arrive, allowing the gateway
    // to broadcast partial output to the UI in real time.
    if ($streaming) {
        header('Content-Type: application/x-ndjson');
        header('X-Accel-Buffering: no');
        header('Cache-Control: no-cache');

        // Disable PHP output buffering so events are sent immediately
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        $stdoutLineBuffer = '';
        $lastHeartbeat = time();
        $heartbeatInterval = 5;  // Send heartbeat every 5s to keep TCP alive

        // Send initial event immediately to confirm connection is alive
        stream_event(['type' => 'started', 'agent' => $agentKey, 'timestamp' => time()]);

        while (true) {
            $status = proc_get_status($process);

            $chunk1 = stream_get_contents($pipes[1]);
            $chunk2 = stream_get_contents($pipes[2]);

            if ($chunk1 !== false && $chunk1 !== '') {
                $stdout .= $chunk1;
                $stdoutLineBuffer .= $chunk1;
                $lastDataTime = time();
                $hasReceivedData = true;

                // Forward complete lines as NDJSON events
                while (($nlPos = strpos($stdoutLineBuffer, "\n")) !== false) {
                    $line = substr($stdoutLineBuffer, 0, $nlPos);
                    $stdoutLineBuffer = substr($stdoutLineBuffer, $nlPos + 1);

                    if (trim($line) !== '') {
                        stream_event(['type' => 'output', 'data' => trim($line)]);
                    }
                }
            }
            if ($chunk2 !== false && $chunk2 !== '') {
                $stderr .= $chunk2;
            }

            if (! $status['running']) {
                break;
            }

            // Send periodic heartbeat to keep the TCP connection alive.
            // Without this, Docker Desktop's macOS networking drops idle
            // connections after ~60s, causing "Connection refused" errors.
            if ((time() - $lastHeartbeat) >= $heartbeatInterval) {
                $elapsedSec = (int) ((hrtime(true) - $startTime) / 1_000_000_000);
                stream_event(['type' => 'heartbeat', 'elapsed_s' => $elapsedSec]);
                $lastHeartbeat = time();
            }

            if ($hasReceivedData && (time() - $lastDataTime) > $inactivityLimit) {
                proc_terminate($process, 15);
                usleep(500_000);
                if (proc_get_status($process)['running']) {
                    proc_terminate($process, 9);
                }
                break;
            }

            if (time() > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);

                $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
                stream_event([
                    'type' => 'done',
                    'success' => false,
                    'error' => "Process timed out after {$timeout}s",
                    'output' => $stdout,
                    'stderr' => $stderr,
                    'exit_code' => -1,
                    'execution_time_ms' => $elapsedMs,
                ]);

                return true;
            }

            usleep(50_000);
        }

        // Flush remaining buffer
        if (trim($stdoutLineBuffer) !== '') {
            stream_event(['type' => 'output', 'data' => trim($stdoutLineBuffer)]);
        }

        // Read any remaining output
        $chunk1 = stream_get_contents($pipes[1]);
        $chunk2 = stream_get_contents($pipes[2]);
        if ($chunk1 !== false && $chunk1 !== '') {
            $stdout .= $chunk1;
        }
        if ($chunk2 !== false && $chunk2 !== '') {
            $stderr .= $chunk2;
        }

        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        error_log("Bridge: [{$agentKey}] exited code={$exitCode} stdout_len=".strlen($stdout).' stderr_len='.strlen($stderr)." elapsed={$elapsedMs}ms");
        if ($stderr !== '') {
            error_log("Bridge: [{$agentKey}] stderr: ".substr($stderr, 0, 500));
        }
        if ($stdout === '' && $exitCode !== 0) {
            error_log("Bridge: [{$agentKey}] WARNING: no stdout produced, exit code {$exitCode}");
        }

        // Build diagnostic error message (same logic as non-streaming path)
        $errorMsg = null;
        if ($exitCode !== 0) {
            $errorMsg = $stderr ?: 'Process exited with non-zero code';
            if (empty($stderr) && ! empty($stdout)) {
                $errorMsg .= ' | stdout: '.substr($stdout, 0, 300);
            }
        }

        stream_event([
            'type' => 'done',
            'success' => $exitCode === 0,
            'output' => $stdout,
            'stderr' => $stderr,
            'error' => $errorMsg,
            'exit_code' => $exitCode,
            'execution_time_ms' => $elapsedMs,
        ]);

        return true;
    }

    // ─── NON-STREAMING MODE (original) ───────────────────────────────
    while (true) {
        $status = proc_get_status($process);

        $chunk1 = stream_get_contents($pipes[1]);
        $chunk2 = stream_get_contents($pipes[2]);

        if ($chunk1 !== false && $chunk1 !== '') {
            $stdout .= $chunk1;
            $lastDataTime = time();
            $hasReceivedData = true;
        }
        if ($chunk2 !== false && $chunk2 !== '') {
            $stderr .= $chunk2;
        }

        if (! $status['running']) {
            break;
        }

        // Inactivity detection: once we've received output from the CLI, if
        // no new data arrives for $inactivityLimit seconds, the agent has
        // finished producing output but the process lingers (e.g. Claude Code
        // keeps TCP connections open for 10+ minutes).  Kill it gracefully.
        if ($hasReceivedData && (time() - $lastDataTime) > $inactivityLimit) {
            proc_terminate($process, 15); // SIGTERM first
            usleep(500_000); // 500ms grace
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9); // SIGKILL if still alive
            }
            break;
        }

        if (time() > $deadline) {
            proc_terminate($process, 9);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);

            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            json_response([
                'success' => false,
                'error' => "Process timed out after {$timeout}s",
                'exit_code' => -1,
                'execution_time_ms' => $elapsedMs,
            ], 504);

            return true;
        }

        usleep(50_000); // 50ms poll
    }

    // Read any remaining output
    $chunk1 = stream_get_contents($pipes[1]);
    $chunk2 = stream_get_contents($pipes[2]);
    if ($chunk1 !== false && $chunk1 !== '') {
        $stdout .= $chunk1;
    }
    if ($chunk2 !== false && $chunk2 !== '') {
        $stderr .= $chunk2;
    }

    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

    error_log("Bridge: [{$agentKey}] exited code={$exitCode} stdout_len=".strlen($stdout).' stderr_len='.strlen($stderr)." elapsed={$elapsedMs}ms");
    if ($stderr !== '') {
        error_log("Bridge: [{$agentKey}] stderr: ".substr($stderr, 0, 500));
    }

    if ($exitCode !== 0) {
        // When stderr is empty (common for Claude Code which reports errors in JSON stdout),
        // include a stdout snippet in the error for diagnostics
        $errorMsg = $stderr ?: 'Process exited with non-zero code';
        if (empty($stderr) && ! empty($stdout)) {
            $preview = substr($stdout, 0, 300);
            $errorMsg .= ' | stdout: '.$preview;
        }

        json_response([
            'success' => false,
            'output' => $stdout,
            'stderr' => $stderr,
            'error' => $errorMsg,
            'exit_code' => $exitCode,
            'execution_time_ms' => $elapsedMs,
        ]);

        return true;
    }

    json_response([
        'success' => true,
        'output' => $stdout,
        'stderr' => $stderr,
        'exit_code' => 0,
        'execution_time_ms' => $elapsedMs,
    ]);

    return true;
}

// --- 404 fallback ---------------------------------------------------------
json_response(['error' => 'Not found'], 404);

return true;
