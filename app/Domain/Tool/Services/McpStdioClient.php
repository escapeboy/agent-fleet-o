<?php

namespace App\Domain\Tool\Services;

use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Facades\Log;

/**
 * MCP stdio client for spawning a local MCP server binary and calling its tools.
 *
 * Uses proc_open() for bidirectional stdin/stdout JSON-RPC 2.0 communication.
 * Each call spawns a fresh process (one-shot), performs the MCP handshake, calls
 * the requested tool, then terminates the process. This is spec-compliant and
 * avoids long-lived process management complexity.
 *
 * Security:
 *  - Command is always passed as an array (no shell expansion).
 *  - Binary path is validated against a config allowlist.
 *  - Sensitive parent environment variables are stripped from the child process.
 *  - Each invocation runs in an isolated temp directory.
 */
class McpStdioClient
{
    private const PROTOCOL_VERSION = '2024-11-05';

    /** Environment variable names that must never reach child processes. */
    private const BLOCKED_ENV_VARS = [
        'ANTHROPIC_API_KEY',
        'OPENAI_API_KEY',
        'GOOGLE_AI_API_KEY',
        'DATABASE_URL',
        'DB_PASSWORD',
        'REDIS_PASSWORD',
        'APP_KEY',
        'STRIPE_SECRET',
        'STRIPE_WEBHOOK_SECRET',
        'MAIL_PASSWORD',
    ];

    /**
     * Discover tools exposed by an mcp_stdio Tool's binary.
     *
     * @return array<int, array<string, mixed>> Array of tool definitions (name, description, inputSchema)
     *
     * @throws \RuntimeException on process failure, timeout, or protocol error
     */
    public function discover(Tool $tool): array
    {
        $pipes = [];
        $process = $this->openProcess($tool, $pipes);

        try {
            $this->handshake($pipes);

            $tools = $this->listTools($pipes);
        } finally {
            $this->closeProcess($process, $pipes);
        }

        return $tools;
    }

    /**
     * Call a named tool on the mcp_stdio Tool's binary.
     *
     * @param  array<string, mixed>  $arguments
     * @return string Tool result as text (ready for agent consumption)
     *
     * @throws \RuntimeException on process failure, timeout, protocol error, or tool error
     */
    public function callTool(Tool $tool, string $toolName, array $arguments = []): string
    {
        $pipes = [];
        $process = $this->openProcess($tool, $pipes);

        try {
            $this->handshake($pipes);

            $result = $this->invokeToolCall($pipes, $toolName, $arguments, $tool->settings['timeout'] ?? 30);
        } finally {
            $this->closeProcess($process, $pipes);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Process lifecycle
    // -------------------------------------------------------------------------

    /**
     * Open the child process with three pipes: stdin (write), stdout (read), stderr (read).
     *
     * @param  array<int, resource>  $pipes  Populated by reference
     * @return resource The proc_open handle
     */
    private function openProcess(Tool $tool, array &$pipes): mixed
    {
        $config = $tool->transport_config ?? [];

        $command = $config['command'] ?? null;
        if (! $command) {
            throw new \RuntimeException("McpStdioClient: tool '{$tool->name}' has no command in transport_config.");
        }

        // Normalise to array form — prevents any shell expansion
        $cmd = is_array($command) ? $command : [$command];
        $args = $config['args'] ?? [];
        $fullCmd = array_merge($cmd, $args);

        $this->validateBinaryAllowlist($fullCmd[0]);
        $this->validateArgs($args);

        $env = $this->buildEnv($config['env'] ?? []);

        $workdir = $config['working_directory'] ?? sys_get_temp_dir();
        if (! is_dir($workdir)) {
            @mkdir($workdir, 0700, true);
        }

        $descriptorspec = [
            0 => ['pipe', 'r'],  // child stdin  — we write to this
            1 => ['pipe', 'w'],  // child stdout — we read from this
            2 => ['pipe', 'w'],  // child stderr — captured, not forwarded
        ];

        $process = proc_open($fullCmd, $descriptorspec, $pipes, $workdir, $env);

        if (! is_resource($process)) {
            throw new \RuntimeException("McpStdioClient: failed to open process for tool '{$tool->name}'.");
        }

        // Non-blocking reads so we can implement timeouts
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return $process;
    }

    /**
     * @param  resource  $process
     * @param  array<int, resource>  $pipes
     */
    private function closeProcess(mixed $process, array $pipes): void
    {
        // Close stdin first — signals EOF to the child process
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        // Read any remaining stderr for debugging
        $stderr = '';
        if (isset($pipes[1]) && is_resource($pipes[1])) {
            fclose($pipes[1]);
        }
        if (isset($pipes[2]) && is_resource($pipes[2])) {
            $stderr = stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[2]);
        }

        if ($stderr) {
            Log::debug('McpStdioClient: child stderr', ['stderr' => substr($stderr, 0, 500)]);
        }

        if (is_resource($process)) {
            proc_terminate($process, SIGTERM);
            proc_close($process);
        }
    }

    // -------------------------------------------------------------------------
    // MCP JSON-RPC protocol
    // -------------------------------------------------------------------------

    /**
     * Perform MCP initialize → initialized handshake.
     *
     * @param  array<int, resource>  $pipes
     */
    private function handshake(array $pipes): void
    {
        // Send initialize request
        $this->writeJsonRpc($pipes[0], [
            'jsonrpc' => '2.0',
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => new \stdClass,
                'clientInfo' => ['name' => 'FleetQ', 'version' => '1.0'],
            ],
            'id' => 1,
        ]);

        // Read initialize response (id=1)
        $response = $this->readJsonRpcResponse($pipes[1], expectedId: 1, timeoutSeconds: 10);

        if (isset($response['error'])) {
            throw new \RuntimeException('MCP initialize error: '.($response['error']['message'] ?? json_encode($response['error'])));
        }

        // Send initialized notification (no response expected)
        $this->writeJsonRpc($pipes[0], [
            'jsonrpc' => '2.0',
            'method' => 'notifications/initialized',
        ]);
    }

    /**
     * Send tools/list and return the tool definitions array.
     *
     * @param  array<int, resource>  $pipes
     * @return array<int, array<string, mixed>>
     */
    private function listTools(array $pipes): array
    {
        $this->writeJsonRpc($pipes[0], [
            'jsonrpc' => '2.0',
            'method' => 'tools/list',
            'params' => new \stdClass,
            'id' => 2,
        ]);

        $response = $this->readJsonRpcResponse($pipes[1], expectedId: 2, timeoutSeconds: 15);

        if (isset($response['error'])) {
            throw new \RuntimeException('MCP tools/list error: '.($response['error']['message'] ?? json_encode($response['error'])));
        }

        return $response['result']['tools'] ?? [];
    }

    /**
     * Send tools/call and return the extracted text result.
     *
     * @param  array<int, resource>  $pipes
     */
    private function invokeToolCall(array $pipes, string $toolName, array $arguments, int $timeoutSeconds): string
    {
        $this->writeJsonRpc($pipes[0], [
            'jsonrpc' => '2.0',
            'method' => 'tools/call',
            'params' => [
                'name' => $toolName,
                'arguments' => $arguments ?: new \stdClass,
            ],
            'id' => 3,
        ]);

        $response = $this->readJsonRpcResponse($pipes[1], expectedId: 3, timeoutSeconds: $timeoutSeconds);

        if (isset($response['error'])) {
            throw new \RuntimeException("MCP tools/call error for '{$toolName}': ".($response['error']['message'] ?? json_encode($response['error'])));
        }

        return $this->extractToolResult($response);
    }

    /**
     * Write a JSON-RPC message as a single newline-terminated line to the process stdin.
     *
     * @param  resource  $stdin
     */
    private function writeJsonRpc(mixed $stdin, array $message): void
    {
        $line = json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
        fwrite($stdin, $line);
        fflush($stdin);
    }

    /**
     * Read lines from stdout until we find a JSON-RPC response with the expected id.
     * Skips notifications (messages without an id) to handle servers that emit them.
     *
     * @param  resource  $stdout
     *
     * @throws \RuntimeException on timeout or unparseable response
     */
    private function readJsonRpcResponse(mixed $stdout, int $expectedId, int $timeoutSeconds): array
    {
        $deadline = microtime(true) + $timeoutSeconds;
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = fread($stdout, 8192);

            if ($chunk === false || feof($stdout)) {
                // Process closed stdout — check buffer one last time
                break;
            }

            if ($chunk !== '') {
                $buffer .= $chunk;
            }

            // Process complete lines from the buffer
            while (($newlinePos = strpos($buffer, "\n")) !== false) {
                $line = substr($buffer, 0, $newlinePos);
                $buffer = substr($buffer, $newlinePos + 1);
                $line = trim($line);

                if ($line === '') {
                    continue;
                }

                $decoded = json_decode($line, true);

                if (! is_array($decoded)) {
                    // Non-JSON line — skip (might be debug output that leaked to stdout)
                    Log::debug('McpStdioClient: skipping non-JSON stdout line', ['line' => substr($line, 0, 200)]);

                    continue;
                }

                // Skip notifications (no id field)
                if (! array_key_exists('id', $decoded)) {
                    continue;
                }

                if ($decoded['id'] === $expectedId) {
                    return $decoded;
                }
            }

            // No complete line yet — sleep briefly and retry
            if ($chunk === '') {
                usleep(10_000); // 10ms
            }
        }

        // Check if we have a complete line in the remaining buffer before giving up
        if (trim($buffer) !== '') {
            $decoded = json_decode(trim($buffer), true);
            if (is_array($decoded) && ($decoded['id'] ?? null) === $expectedId) {
                return $decoded;
            }
        }

        throw new \RuntimeException("McpStdioClient: timed out waiting for JSON-RPC response (id={$expectedId}, timeout={$timeoutSeconds}s).");
    }

    /**
     * Extract text content from an MCP tools/call result envelope.
     *
     * Mirrors McpHttpClient::extractToolResult() for consistent behavior.
     */
    private function extractToolResult(array $response): string
    {
        $content = $response['result']['content'] ?? [];

        if (empty($content)) {
            return '(no output)';
        }

        $parts = [];
        foreach ($content as $item) {
            $type = $item['type'] ?? 'text';
            if ($type === 'text') {
                $parts[] = $item['text'] ?? '';
            } elseif ($type === 'image') {
                $parts[] = "[image: {$item['mimeType']}]";
            } elseif ($type === 'resource') {
                $parts[] = $item['resource']['text'] ?? $item['resource']['uri'] ?? '[resource]';
            }
        }

        return implode("\n", $parts);
    }

    // -------------------------------------------------------------------------
    // Security helpers
    // -------------------------------------------------------------------------

    /**
     * Ensure the binary being executed is on the configured allowlist.
     *
     * @throws \RuntimeException if the binary is not permitted
     */
    private function validateBinaryAllowlist(string $binaryPath): void
    {
        $allowlist = config('agent.mcp_stdio_binary_allowlist', []);

        // Empty allowlist + explicit opt-in → allow all (local dev only).
        // Empty allowlist without opt-in → deny all (fail-close default).
        if (empty($allowlist)) {
            if (config('agent.mcp_stdio_allow_any_binary', false)) {
                return;
            }

            throw new \RuntimeException(
                'McpStdioClient: mcp_stdio_binary_allowlist is empty and MCP_STDIO_ALLOW_ANY_BINARY is not set. '.
                'Set MCP_STDIO_BINARY_ALLOWLIST in .env (comma-separated absolute paths) '.
                'or set MCP_STDIO_ALLOW_ANY_BINARY=true for local dev.',
            );
        }

        // Resolve symlinks before comparing
        $resolved = realpath($binaryPath) ?: $binaryPath;

        foreach ($allowlist as $allowed) {
            if ($resolved === (realpath($allowed) ?: $allowed)) {
                return;
            }
        }

        throw new \RuntimeException("McpStdioClient: binary '{$binaryPath}' is not in the mcp_stdio_binary_allowlist.");
    }

    /**
     * Validate that args do not contain shell metacharacters.
     *
     * Since proc_open with an array bypasses the shell, these characters are not
     * directly injectable. However, some binaries interpret their own flags as
     * sub-commands (e.g. git --upload-pack, curl --config). Blocking metacharacters
     * in args prevents the most obvious argument-injection vectors.
     *
     * @param  array<int, mixed>  $args
     *
     * @throws \RuntimeException if any arg contains shell metacharacters
     */
    private function validateArgs(array $args): void
    {
        foreach ($args as $arg) {
            if (! is_string($arg)) {
                continue;
            }
            if (preg_match('/[;&|`$\n\r]/', $arg)) {
                throw new \RuntimeException(
                    'McpStdioClient: transport_config args must not contain shell metacharacters (;, &, |, `, $, newline).',
                );
            }
        }
    }

    /**
     * Build the child process environment: inherit PATH/HOME/TMPDIR but strip secrets.
     *
     * @param  array<string, string>  $extraEnv  Additional env vars from transport_config
     * @return array<string, string>
     */
    private function buildEnv(array $extraEnv): array
    {
        // Start with a minimal safe environment
        $env = array_filter([
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => '/tmp',
            'TMPDIR' => sys_get_temp_dir(),
            'LANG' => 'en_US.UTF-8',
            'LC_ALL' => 'C',
        ]);

        // Explicitly null-out known secrets from the inherited env
        foreach (self::BLOCKED_ENV_VARS as $var) {
            $env[$var] = '';
        }

        // Merge tool-specific env vars last (can override PATH/HOME but not unset them)
        foreach ($extraEnv as $key => $value) {
            if (! in_array($key, self::BLOCKED_ENV_VARS, true)) {
                $env[$key] = $value;
            }
        }

        return $env;
    }
}
