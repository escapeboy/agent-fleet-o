<?php

namespace App\Infrastructure\AI\Gateways;

use App\Domain\Audit\Models\AuditEntry;
use App\Domain\Shared\Models\Team;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Exceptions\VpsLocalAgentException;
use App\Infrastructure\AI\Services\ClaudeCodeVpsConcurrencyCap;
use App\Infrastructure\AI\Services\ClaudeCodeVpsGate;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
use App\Models\User;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class LocalAgentGateway implements AiGatewayInterface
{
    public function __construct(
        private readonly LocalAgentDiscovery $discovery,
    ) {}

    public function complete(AiRequestDTO $request): AiResponseDTO
    {
        $agentKey = $this->resolveAgentKey($request->provider, $request->model);
        $config = config("local_agents.agents.{$agentKey}");

        if (! $config) {
            throw new RuntimeException("Unknown local agent: {$agentKey}");
        }

        if (! empty($config['vps_only'])) {
            return $this->executeVps($agentKey, $config, $request, null);
        }

        if ($this->discovery->isBridgeMode()) {
            return $this->executeViaBridge($agentKey, $config, $request);
        }

        $binaryPath = $this->discovery->binaryPath($agentKey);

        if (! $binaryPath) {
            throw new RuntimeException("Local agent '{$config['name']}' is not available on this system.");
        }

        $isAssistant = $request->purpose === 'platform_assistant';
        $timeout = config('local_agents.timeout', 300);
        $workdir = $this->resolveWorkingDirectory(
            $request->workingDirectory ?? config('local_agents.working_directory'),
        );

        // Claude Code assistant: use array-based Process for proper system prompt
        // separation via --system-prompt-file flag (avoids ARG_MAX when tool list is large).
        if ($agentKey === 'claude-code' && $isAssistant) {
            $systemPromptFile = $workdir.'/system-prompt.txt';
            file_put_contents($systemPromptFile, $request->systemPrompt);
            $args = LocalAgentPromptBuilder::buildClaudeCodeAssistantArgs($binaryPath, $request->systemPrompt, $request->model, $systemPromptFile);
            $prompt = $request->userPrompt;
            $env = getenv();
            unset($env['CLAUDECODE']);

            Log::debug("LocalAgentGateway: executing {$agentKey} (assistant mode)", [
                'model' => $request->model,
                'prompt_length' => strlen($prompt),
                'system_prompt_length' => strlen($request->systemPrompt),
                'timeout' => $timeout,
            ]);

            $startTime = hrtime(true);
            $process = new Process($args, $workdir, $env, $prompt, $timeout);
            $process->run();
        } else {
            $prompt = LocalAgentPromptBuilder::buildPrompt($request);
            $usesStdin = LocalAgentPromptBuilder::readsFromStdin($agentKey);
            $command = LocalAgentPromptBuilder::buildCommand(
                $agentKey, $binaryPath, $request->model, $request->purpose,
                $usesStdin ? null : $prompt,
            );

            Log::debug("LocalAgentGateway: executing {$agentKey}", [
                'command' => $command,
                'model' => $request->model,
                'prompt_length' => strlen($prompt),
                'timeout' => $timeout,
                'stdin' => $usesStdin,
            ]);

            $startTime = hrtime(true);
            $process = Process::fromShellCommandline(
                $command, $workdir, null,
                $usesStdin ? $prompt : null,
                $timeout,
            );
            $process->run();
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        if (! $process->isSuccessful()) {
            $stderr = $process->getErrorOutput();
            $stdout = $process->getOutput();
            $exitCode = $process->getExitCode();

            // Claude Code may exit code 1 but still produce valid output on stdout
            if ($stdout !== '' && $exitCode <= 1) {
                Log::warning("LocalAgentGateway: {$agentKey} exited {$exitCode} but has stdout, attempting recovery", [
                    'exit_code' => $exitCode,
                    'stdout_length' => strlen($stdout),
                ]);

                try {
                    $parsed = LocalAgentOutputParser::parseOutput($agentKey, $stdout);
                    if ($parsed['content'] !== '') {
                        return new AiResponseDTO(
                            content: $parsed['content'],
                            parsedOutput: $parsed['structured'],
                            usage: new AiUsageDTO(
                                promptTokens: LocalAgentOutputParser::estimateTokens($prompt),
                                completionTokens: LocalAgentOutputParser::estimateTokens($parsed['content']),
                                costCredits: 0,
                            ),
                            provider: $request->provider,
                            model: $request->model,
                            latencyMs: $latencyMs,
                        );
                    }
                } catch (\Throwable $parseErr) {
                    Log::debug('LocalAgentGateway: recovery parse failed', ['error' => $parseErr->getMessage()]);
                }
            }

            Log::error("LocalAgentGateway: {$agentKey} failed", [
                'exit_code' => $exitCode,
                'stderr' => substr($stderr, 0, 1000),
                'stdout_preview' => substr($stdout, 0, 500),
            ]);

            $diagnosticMsg = $stderr ?: 'No error output';
            if (empty($stderr) && $stdout !== '') {
                $diagnosticMsg = 'stdout: '.substr($stdout, 0, 300);
            }

            throw new RuntimeException(
                "Local agent '{$config['name']}' failed (exit code {$exitCode}): "
                .substr($diagnosticMsg, 0, 500),
            );
        }

        $output = $process->getOutput();
        $parsed = LocalAgentOutputParser::parseOutput($agentKey, $output);

        return new AiResponseDTO(
            content: $parsed['content'],
            parsedOutput: $parsed['structured'],
            usage: new AiUsageDTO(
                promptTokens: LocalAgentOutputParser::estimateTokens($prompt),
                completionTokens: LocalAgentOutputParser::estimateTokens($parsed['content']),
                costCredits: 0,
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $latencyMs,
        );
    }

    public function stream(AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        $agentKey = $this->resolveAgentKey($request->provider, $request->model);
        $config = config("local_agents.agents.{$agentKey}");

        if (! $config) {
            throw new RuntimeException("Unknown local agent: {$agentKey}");
        }

        // VPS-only agents run on the server itself via executeVps — they must
        // never be routed through the host bridge, even in bridge mode. Mirror
        // the vps_only guard from complete() so the tool-loop streaming path
        // (LocalToolLoopExecutor → gateway->stream) reaches the right handler.
        // Pass the onChunk callback through so executeVps can emit progressive
        // chunks by parsing stream-json events from stdout incrementally
        // instead of dumping the whole response at the end.
        if (! empty($config['vps_only'])) {
            return $this->executeVps($agentKey, $config, $request, $onChunk);
        }

        // Bridge mode: use NDJSON streaming for real-time output
        if ($this->discovery->isBridgeMode()) {
            return $this->streamViaBridge($agentKey, $config, $request, $onChunk);
        }

        // Direct execution: no real streaming for CLI processes.
        // Execute normally and deliver the full output as a single chunk.
        $response = $this->complete($request);

        if ($onChunk && $response->content) {
            $onChunk($response->content);
        }

        return $response;
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0;
    }

    /**
     * Execute an agent via the host bridge HTTP server.
     */
    /**
     * Execute the VPS-installed Claude Code with a pre-provisioned Max OAuth token.
     *
     * This path is super-admin gated and completely self-contained: no bridge, no
     * relay, no shared working directory. Each call runs in an ephemeral tmpdir,
     * inherits only a scrubbed environment, and is bounded by a per-team
     * concurrency cap. All invocations are written to the audit log.
     */
    private function executeVps(string $agentKey, array $config, AiRequestDTO $request, ?callable $onChunk = null): AiResponseDTO
    {
        $gate = app(ClaudeCodeVpsGate::class);
        $cap = app(ClaudeCodeVpsConcurrencyCap::class);

        $user = $request->userId ? User::find($request->userId) : null;
        $team = $request->teamId ? Team::withoutGlobalScopes()->find($request->teamId) : null;

        $gate->assertAllowed($user, $team);

        $binaryPath = $this->discovery->vpsBinaryPath();
        if (! $binaryPath) {
            throw VpsLocalAgentException::binaryMissing(
                (string) config('local_agents.vps.binary_path'),
            );
        }

        $teamId = $request->teamId ?? 'platform';
        $slotToken = $cap->acquire($teamId);
        if ($slotToken === false) {
            throw VpsLocalAgentException::concurrencyCapReached($cap->cap());
        }

        $workdir = $this->makeEphemeralWorkdir();
        $timeout = (int) config('local_agents.vps.timeout_seconds', 300);
        $oauthToken = (string) config('local_agents.vps.oauth_token');

        // Assistant mode uses the same --system-prompt / --tools "" separation as
        // the regular claude-code assistant path. That's what lets our text-based
        // <tool_call> loop work reliably — tool instructions live in system_prompt,
        // user input goes on stdin, and --tools "" disables the CLI's own
        // filesystem/bash tools so the agent has to emit our tag format.
        //
        // When an $onChunk callback is provided we use stream-json output so
        // we can emit progressive assistant text chunks by reading stdout
        // incrementally instead of waiting for the final JSON payload.
        $isAssistant = $request->purpose === 'platform_assistant';
        $useStreaming = $isAssistant && $onChunk !== null;

        if ($isAssistant) {
            // Write system prompt to a file in the ephemeral workdir to avoid ARG_MAX
            // when the tool list is large (230+ tools → system prompt can exceed 128KB).
            // The file is cleaned up automatically with the workdir in finally{}.
            $systemPromptFile = $workdir.'/system-prompt.txt';
            file_put_contents($systemPromptFile, $request->systemPrompt);
            $args = $useStreaming
                ? LocalAgentPromptBuilder::buildClaudeCodeAssistantStreamArgs($binaryPath, $request->systemPrompt, $request->model, $systemPromptFile)
                : LocalAgentPromptBuilder::buildClaudeCodeAssistantArgs($binaryPath, $request->systemPrompt, $request->model, $systemPromptFile);
            $prompt = $request->userPrompt;
        } else {
            $prompt = LocalAgentPromptBuilder::buildPrompt($request);
            $args = array_merge(
                [$binaryPath],
                $config['execute_flags'] ?? ['-p', '--output-format', 'stream-json', '--verbose', '--dangerously-skip-permissions'],
            );
            if ($request->model) {
                $args[] = '--model';
                $args[] = $request->model;
            }
        }

        $env = [
            'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
            'HOME' => $workdir,
            'CLAUDE_CODE_OAUTH_TOKEN' => $oauthToken,
            // Claude Code refuses --dangerously-skip-permissions when running as root
            // for safety reasons. On the VPS this process runs inside the app container
            // (root by default in php-fpm-alpine) so we opt into the documented sandbox
            // escape hatch. The surrounding container + ephemeral workdir + scrubbed env
            // form the real sandbox — this flag just tells the CLI to accept it.
            'IS_SANDBOX' => '1',
        ];

        Log::info('LocalAgentGateway: executing claude-code-vps', [
            'team_id' => $teamId,
            'user_id' => $request->userId,
            'model' => $request->model,
            'prompt_length' => strlen($prompt),
            'workdir' => $workdir,
            'timeout' => $timeout,
            'streaming' => $useStreaming,
        ]);

        $startTime = hrtime(true);
        $exitCode = null;
        $stderr = '';

        try {
            $process = new Process($args, $workdir, $env, $prompt, $timeout);

            if ($useStreaming) {
                $this->runVpsProcessStreaming($process, $onChunk);
            } else {
                $process->run();
            }

            $exitCode = $process->getExitCode();
            $stderr = (string) $process->getErrorOutput();
            $stdout = (string) $process->getOutput();

            $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            if (! $process->isSuccessful() && $stdout === '') {
                throw new RuntimeException(
                    "Claude Code (VPS) failed (exit {$exitCode}): ".substr($stderr ?: 'no error output', 0, 500),
                );
            }

            $parsed = LocalAgentOutputParser::parseOutput($agentKey, $stdout);

            return new AiResponseDTO(
                content: $parsed['content'],
                parsedOutput: $parsed['structured'],
                usage: new AiUsageDTO(
                    promptTokens: LocalAgentOutputParser::estimateTokens($prompt),
                    completionTokens: LocalAgentOutputParser::estimateTokens($parsed['content']),
                    costCredits: 0,
                ),
                provider: $request->provider,
                model: $request->model,
                latencyMs: $latencyMs,
            );
        } finally {
            $cap->release($teamId, $slotToken);
            $this->removeEphemeralWorkdir($workdir);
            $this->recordVpsAudit($request, $user, $team, $exitCode, $startTime, $stderr);
        }
    }

    /**
     * Run the claude-code-vps process with incremental stdout reading and
     * emit progressive text chunks via $onChunk as the CLI streams JSONL
     * events. Strips <tool_call> blocks from the visible stream so the UI
     * never flashes raw tag markup.
     */
    private function runVpsProcessStreaming(Process $process, callable $onChunk): void
    {
        $buffer = '';
        $accumulatedText = '';
        $lastEmittedLength = 0;

        $process->start();

        $process->wait(function (string $type, string $data) use (
            &$buffer,
            &$accumulatedText,
            &$lastEmittedLength,
            $onChunk
        ): void {
            if ($type !== Process::OUT) {
                return;
            }

            $buffer .= $data;

            // Parse every complete JSONL line that arrived in this read.
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                $event = json_decode($line, true);
                if (! is_array($event)) {
                    continue;
                }

                // Claude Code stream-json: assistant events carry progressive
                // text blocks in message.content[].
                if (($event['type'] ?? '') !== 'assistant') {
                    continue;
                }
                $contentBlocks = $event['message']['content'] ?? null;
                if (! is_array($contentBlocks)) {
                    continue;
                }
                foreach ($contentBlocks as $block) {
                    if (($block['type'] ?? '') !== 'text') {
                        continue;
                    }
                    $text = $block['text'] ?? '';
                    if (! is_string($text) || $text === '') {
                        continue;
                    }
                    $accumulatedText .= $text;

                    // Hide in-progress <tool_call> markup + anything after the
                    // Gap 2 artifact delimiter so the UI never renders raw JSON.
                    $visible = preg_replace('/<tool_call>.*?<\/tool_call>/s', '', $accumulatedText);
                    $visible = preg_replace('/<tool_call>[\s\S]*$/', '', $visible);
                    $artifactStart = strpos($visible, '<<<FLEETQ_ARTIFACTS>>>');
                    if ($artifactStart !== false) {
                        $visible = substr($visible, 0, $artifactStart);
                    }
                    $visible = rtrim($visible);

                    if ($visible !== '' && strlen($visible) > $lastEmittedLength) {
                        $onChunk($visible);
                        $lastEmittedLength = strlen($visible);
                    }
                }
            }
        });
    }

    private function makeEphemeralWorkdir(): string
    {
        $base = sys_get_temp_dir().'/claude-vps';
        if (! is_dir($base)) {
            @mkdir($base, 0700, true);
        }

        $path = $base.'/'.bin2hex(random_bytes(8));
        mkdir($path, 0700, true);

        return $path;
    }

    private function removeEphemeralWorkdir(string $path): void
    {
        if (! str_starts_with($path, sys_get_temp_dir().'/claude-vps/')) {
            return;
        }

        $items = @scandir($path) ?: [];
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $target = $path.'/'.$item;
            if (is_dir($target)) {
                $this->removeEphemeralWorkdir($target);
            } else {
                @unlink($target);
            }
        }

        @rmdir($path);
    }

    private function recordVpsAudit(
        AiRequestDTO $request,
        ?User $user,
        ?Team $team,
        ?int $exitCode,
        int $startTime,
        string $stderr,
    ): void {
        try {
            AuditEntry::create([
                'team_id' => $team?->id,
                'user_id' => $user?->id,
                'event' => 'claude_code_vps.invoke',
                'subject_type' => $team ? Team::class : null,
                'subject_id' => $team?->id,
                'properties' => [
                    'model' => $request->model,
                    'prompt_length' => strlen($request->userPrompt),
                    'duration_ms' => (int) ((hrtime(true) - $startTime) / 1_000_000),
                    'exit_code' => $exitCode,
                    'stderr_preview' => $stderr !== '' ? substr($stderr, 0, 200) : null,
                ],
                'created_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('LocalAgentGateway: failed to write VPS audit entry', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function executeViaBridge(string $agentKey, array $config, AiRequestDTO $request): AiResponseDTO
    {
        $prompt = LocalAgentPromptBuilder::buildPrompt($request);
        $bridgeTimeout = config('local_agents.timeout', 300);  // Time the bridge gives the CLI process
        $httpTimeout = $bridgeTimeout + 60;                     // HTTP timeout = bridge timeout + buffer
        $bridgeUrl = $this->discovery->bridgeUrl();
        $bridgeSecret = $this->discovery->bridgeSecret();

        Log::info("LocalAgentGateway: executing {$agentKey} via bridge", [
            'bridge_url' => $bridgeUrl,
            'prompt_length' => strlen($prompt),
            'bridge_timeout' => $bridgeTimeout,
            'http_timeout' => $httpTimeout,
        ]);

        $startTime = hrtime(true);

        try {
            // CURLOPT_NOSIGNAL=1 is critical: without it, cURL uses alarm() for timeouts,
            // which conflicts with pcntl_alarm() used by Horizon's job timeout handler.
            // The conflict can cause the cURL timeout to silently not fire.
            $response = Http::timeout($httpTimeout)
                ->connectTimeout(30)
                ->withOptions([
                    'curl' => [
                        CURLOPT_NOSIGNAL => 1,           // Prevent SIGALRM conflict with Horizon
                        CURLOPT_TCP_KEEPALIVE => 1,      // Keep TCP connection alive
                        CURLOPT_TCP_KEEPIDLE => 60,      // Start keepalive after 60s idle
                        CURLOPT_TCP_KEEPINTVL => 30,     // Keepalive interval
                    ],
                ])
                ->withToken($bridgeSecret)
                ->post($bridgeUrl.'/execute', [
                    'agent_key' => $agentKey,
                    'prompt' => $prompt,
                    'system_prompt' => $request->systemPrompt,
                    'user_prompt' => $request->userPrompt,
                    'model' => $request->model,
                    'timeout' => $bridgeTimeout,
                    'working_directory' => $request->workingDirectory ?? config('local_agents.working_directory'),
                    'purpose' => $request->purpose,
                ]);
        } catch (\Throwable $e) {
            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            Log::error("LocalAgentGateway: bridge HTTP failed for {$agentKey}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
                'http_timeout' => $httpTimeout,
            ]);

            throw new RuntimeException(
                "Bridge connection failed for '{$config['name']}' after {$elapsedMs}ms: ".$e->getMessage(),
            );
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $data = $response->json();

        if (! $response->successful() || ! ($data['success'] ?? false)) {
            $exitCode = $data['exit_code'] ?? $response->status();
            $stdout = $data['output'] ?? '';
            $stderr = $data['stderr'] ?? '';
            $error = $data['error'] ?? $stderr ?: 'Unknown bridge error';

            // Claude Code may exit with code 1 but still produce valid output on stdout.
            // Attempt to recover the output before giving up.
            if ($stdout !== '' && $exitCode <= 1) {
                Log::warning("LocalAgentGateway: {$agentKey} exited {$exitCode} but has stdout, attempting recovery", [
                    'exit_code' => $exitCode,
                    'stdout_length' => strlen($stdout),
                    'stderr' => substr($stderr, 0, 500),
                ]);

                try {
                    $parsed = LocalAgentOutputParser::parseOutput($agentKey, $stdout);
                    if ($parsed['content'] !== '') {
                        return new AiResponseDTO(
                            content: $parsed['content'],
                            parsedOutput: $parsed['structured'],
                            usage: new AiUsageDTO(
                                promptTokens: LocalAgentOutputParser::estimateTokens($prompt),
                                completionTokens: LocalAgentOutputParser::estimateTokens($parsed['content']),
                                costCredits: 0,
                            ),
                            provider: $request->provider,
                            model: $request->model,
                            latencyMs: $data['execution_time_ms'] ?? $latencyMs,
                        );
                    }
                } catch (\Throwable $parseErr) {
                    Log::debug('LocalAgentGateway: recovery parse failed', [
                        'error' => $parseErr->getMessage(),
                    ]);
                }
            }

            // Build a diagnostic error message including stdout snippet if stderr was empty
            $diagnosticError = $error;
            if ($error === 'Process exited with non-zero code' && $stdout !== '') {
                $diagnosticError .= ' | stdout: '.substr($stdout, 0, 300);
            }

            Log::error("LocalAgentGateway: bridge execution failed for {$agentKey}", [
                'exit_code' => $exitCode,
                'error' => substr($error, 0, 1000),
                'stdout_preview' => substr($stdout, 0, 500),
            ]);

            throw new RuntimeException(
                "Local agent '{$config['name']}' failed via bridge (code {$exitCode}): "
                .substr($diagnosticError, 0, 500),
            );
        }

        $output = $data['output'] ?? '';

        $parsed = LocalAgentOutputParser::parseOutput($agentKey, $output);

        return new AiResponseDTO(
            content: $parsed['content'],
            parsedOutput: $parsed['structured'],
            usage: new AiUsageDTO(
                promptTokens: LocalAgentOutputParser::estimateTokens($prompt),
                completionTokens: LocalAgentOutputParser::estimateTokens($parsed['content']),
                costCredits: 0,
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $data['execution_time_ms'] ?? $latencyMs,
        );
    }

    /**
     * Stream an agent execution via the host bridge using NDJSON events.
     *
     * The bridge spawns the CLI with stream-json output format and forwards
     * each stdout line as an NDJSON event. We read events incrementally,
     * extract text content, and call $onChunk for real-time UI updates.
     */
    private function streamViaBridge(string $agentKey, array $config, AiRequestDTO $request, ?callable $onChunk): AiResponseDTO
    {
        $prompt = LocalAgentPromptBuilder::buildPrompt($request);
        $bridgeTimeout = config('local_agents.timeout', 300);
        $bridgeUrl = $this->discovery->bridgeUrl();
        $bridgeSecret = $this->discovery->bridgeSecret();

        Log::info("LocalAgentGateway: streaming {$agentKey} via bridge", [
            'bridge_url' => $bridgeUrl,
            'prompt_length' => strlen($prompt),
            'bridge_timeout' => $bridgeTimeout,
        ]);

        $startTime = hrtime(true);

        try {
            $client = new GuzzleClient;
            $response = $client->post($bridgeUrl.'/execute', [
                'json' => [
                    'agent_key' => $agentKey,
                    'prompt' => $prompt,
                    'system_prompt' => $request->systemPrompt,
                    'user_prompt' => $request->userPrompt,
                    'model' => $request->model,
                    'timeout' => $bridgeTimeout,
                    'stream' => true,
                    'working_directory' => $request->workingDirectory ?? config('local_agents.working_directory'),
                    'purpose' => $request->purpose,
                ],
                'headers' => [
                    'Authorization' => 'Bearer '.$bridgeSecret,
                ],
                'stream' => true,
                'connect_timeout' => 30,
                'timeout' => 0, // No total timeout — bridge manages its own timeout
                'curl' => [
                    CURLOPT_NOSIGNAL => 1,
                    CURLOPT_TCP_KEEPALIVE => 1,
                    CURLOPT_TCP_KEEPIDLE => 30,       // Start keepalive probes after 30s
                    CURLOPT_TCP_KEEPINTVL => 15,      // Keepalive probe interval
                    CURLOPT_LOW_SPEED_LIMIT => 1,     // At least 1 byte/s...
                    CURLOPT_LOW_SPEED_TIME => 30,     // ...or drop connection after 30s of silence
                ],
            ]);
        } catch (\Throwable $e) {
            $elapsedMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            Log::error("LocalAgentGateway: bridge stream connection failed for {$agentKey}", [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'elapsed_ms' => $elapsedMs,
            ]);

            throw new RuntimeException(
                "Bridge stream failed for '{$config['name']}' after {$elapsedMs}ms: ".$e->getMessage(),
            );
        }

        $body = $response->getBody();
        $fullOutput = '';
        $doneEvent = null;
        $buffer = '';

        while (! $body->eof()) {
            $chunk = $body->read(8192);

            if ($chunk === '' || $chunk === false) {
                usleep(10_000); // 10ms wait for more data

                continue;
            }

            $buffer .= $chunk;

            // Process complete NDJSON lines
            while (($pos = strpos($buffer, "\n")) !== false) {
                $line = trim(substr($buffer, 0, $pos));
                $buffer = substr($buffer, $pos + 1);

                if ($line === '') {
                    continue;
                }

                $event = json_decode($line, true);
                if (! is_array($event)) {
                    continue;
                }

                $eventType = $event['type'] ?? '';

                if ($eventType === 'output') {
                    $data = $event['data'] ?? '';
                    $fullOutput .= $data."\n";

                    // Extract human-readable text from stream-json events
                    if ($onChunk) {
                        $text = LocalAgentOutputParser::extractTextFromStreamEvent($data);
                        if ($text !== null) {
                            $onChunk($text);
                        }
                    }
                }

                // Heartbeat/started events keep the connection alive — just skip them
                if ($eventType === 'heartbeat' || $eventType === 'started') {
                    continue;
                }

                if ($eventType === 'done') {
                    $doneEvent = $event;
                }

                if ($eventType === 'error') {
                    throw new RuntimeException(
                        "Local agent '{$config['name']}' stream error: ".($event['error'] ?? 'Unknown'),
                    );
                }
            }
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        // If no done event, try to parse whatever output we have
        if (! $doneEvent) {
            Log::warning("LocalAgentGateway: stream ended without done event for {$agentKey}", [
                'output_length' => strlen($fullOutput),
            ]);

            if ($fullOutput !== '') {
                $parsed = LocalAgentOutputParser::parseOutput($agentKey, $fullOutput);
                if ($parsed['content'] !== '') {
                    return new AiResponseDTO(
                        content: $parsed['content'],
                        parsedOutput: $parsed['structured'],
                        usage: new AiUsageDTO(
                            promptTokens: LocalAgentOutputParser::estimateTokens($prompt),
                            completionTokens: LocalAgentOutputParser::estimateTokens($parsed['content']),
                            costCredits: 0,
                        ),
                        provider: $request->provider,
                        model: $request->model,
                        latencyMs: $latencyMs,
                    );
                }
            }

            throw new RuntimeException("Bridge stream ended without result for '{$config['name']}'");
        }

        $exitCode = $doneEvent['exit_code'] ?? -1;
        $stdout = $doneEvent['output'] ?? '';
        $stderr = $doneEvent['stderr'] ?? '';
        $success = $doneEvent['success'] ?? ($exitCode === 0);

        Log::debug("LocalAgentGateway: stream done event for {$agentKey}", [
            'exit_code' => $exitCode,
            'success' => $success,
            'stdout_length' => strlen($stdout),
            'stderr_length' => strlen($stderr),
            'streamed_output_length' => strlen($fullOutput),
        ]);

        if (! $success) {
            // Use either the done event's stdout or the accumulated streamed output
            $recoveryOutput = $stdout !== '' ? $stdout : trim($fullOutput);

            // Attempt recovery from exit code 0 or 1 with stdout content
            if ($recoveryOutput !== '' && $exitCode <= 1) {
                Log::warning("LocalAgentGateway: stream {$agentKey} exited {$exitCode} but has output, attempting recovery", [
                    'output_length' => strlen($recoveryOutput),
                ]);

                try {
                    $parsed = LocalAgentOutputParser::parseOutput($agentKey, $recoveryOutput);
                    if ($parsed['content'] !== '') {
                        return new AiResponseDTO(
                            content: $parsed['content'],
                            parsedOutput: $parsed['structured'],
                            usage: new AiUsageDTO(
                                promptTokens: LocalAgentOutputParser::estimateTokens($prompt),
                                completionTokens: LocalAgentOutputParser::estimateTokens($parsed['content']),
                                costCredits: 0,
                            ),
                            provider: $request->provider,
                            model: $request->model,
                            latencyMs: $doneEvent['execution_time_ms'] ?? $latencyMs,
                        );
                    }
                } catch (\Throwable $parseErr) {
                    Log::debug('LocalAgentGateway: stream recovery parse failed', [
                        'error' => $parseErr->getMessage(),
                    ]);
                }
            }

            // Build diagnostic error message
            $error = $doneEvent['error'] ?? null;
            if (! $error) {
                $error = $stderr ?: 'Process exited with non-zero code';
                if (empty($stderr) && $recoveryOutput !== '') {
                    $error .= ' | stdout: '.substr($recoveryOutput, 0, 300);
                }
            }

            throw new RuntimeException(
                "Local agent '{$config['name']}' failed via bridge stream (code {$exitCode}): "
                .substr($error, 0, 500),
            );
        }

        // Use the done event's output (complete), fall back to streamed output
        $finalOutput = $stdout !== '' ? $stdout : trim($fullOutput);
        $parsed = LocalAgentOutputParser::parseOutput($agentKey, $finalOutput);

        return new AiResponseDTO(
            content: $parsed['content'],
            parsedOutput: $parsed['structured'],
            usage: new AiUsageDTO(
                promptTokens: LocalAgentOutputParser::estimateTokens($prompt),
                completionTokens: LocalAgentOutputParser::estimateTokens($parsed['content']),
                costCredits: 0,
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $doneEvent['execution_time_ms'] ?? $latencyMs,
        );
    }

    /**
     * Resolve the local_agents.php agent key from the llm_providers config.
     * If the generic "local" provider is used, resolve to the first available local agent.
     */
    private function resolveAgentKey(string $provider, ?string $model = null): string
    {
        if ($provider === 'local') {
            // If a specific model is requested that maps to a known agent key, use it directly
            // without calling detect() (which can block on single-threaded bridge)
            $knownModels = [
                'claude-code' => 'claude-code',
                'codex' => 'codex',
                'gemini-cli' => 'gemini-cli',
                'kiro' => 'kiro',
                'aider' => 'aider',
                'amp' => 'amp',
                'opencode' => 'opencode',
                'cursor' => 'cursor',
            ];

            if ($model && isset($knownModels[$model])) {
                return $knownModels[$model];
            }

            $detected = $this->discovery->detect();
            if (empty($detected)) {
                throw new RuntimeException(
                    'No local agents detected. Install Codex or Claude Code CLI.',
                );
            }

            return array_key_first($detected);
        }

        return config("llm_providers.{$provider}.agent_key", $provider);
    }

    /**
     * Resolve and validate a working directory path.
     *
     * Prevents path traversal by canonicalising the path with realpath() and
     * verifying the result is a readable directory. Falls back to base_path()
     * when the supplied value is null, empty, or does not resolve to a real
     * directory, so callers can always rely on a valid cwd being returned.
     */
    private function resolveWorkingDirectory(?string $path): string
    {
        if ($path === null || $path === '') {
            return base_path();
        }

        $resolved = realpath($path);

        if ($resolved === false || ! is_dir($resolved)) {
            Log::warning('LocalAgentGateway: invalid working_directory, falling back to base_path', [
                'requested' => $path,
            ]);

            return base_path();
        }

        return $resolved;
    }
}
