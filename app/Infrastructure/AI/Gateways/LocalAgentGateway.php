<?php

namespace App\Infrastructure\AI\Gateways;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
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
        $agentKey = $this->resolveAgentKey($request->provider);
        $config = config("local_agents.agents.{$agentKey}");

        if (! $config) {
            throw new RuntimeException("Unknown local agent: {$agentKey}");
        }

        if ($this->discovery->isBridgeMode()) {
            return $this->executeViaBridge($agentKey, $config, $request);
        }

        $binaryPath = $this->discovery->binaryPath($agentKey);

        if (! $binaryPath) {
            throw new RuntimeException("Local agent '{$config['name']}' is not available on this system.");
        }

        $prompt = $this->buildPrompt($request);
        $command = $this->buildCommand($agentKey, $binaryPath, $request->model);
        $timeout = config('local_agents.timeout', 300);
        $workdir = config('local_agents.working_directory') ?? base_path();

        Log::debug("LocalAgentGateway: executing {$agentKey}", [
            'command' => $command,
            'model' => $request->model,
            'prompt_length' => strlen($prompt),
            'timeout' => $timeout,
        ]);

        $startTime = hrtime(true);

        $process = Process::fromShellCommandline($command, $workdir, null, $prompt, $timeout);
        $process->run();

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        if (! $process->isSuccessful()) {
            $stderr = $process->getErrorOutput();
            $exitCode = $process->getExitCode();

            Log::error("LocalAgentGateway: {$agentKey} failed", [
                'exit_code' => $exitCode,
                'stderr' => substr($stderr, 0, 1000),
            ]);

            throw new RuntimeException(
                "Local agent '{$config['name']}' failed (exit code {$exitCode}): "
                . substr($stderr ?: 'No error output', 0, 500)
            );
        }

        $output = $process->getOutput();
        $parsed = $this->parseOutput($agentKey, $output);

        return new AiResponseDTO(
            content: $parsed['content'],
            parsedOutput: $parsed['structured'],
            usage: new AiUsageDTO(
                promptTokens: $this->estimateTokens($prompt),
                completionTokens: $this->estimateTokens($parsed['content']),
                costCredits: 0,
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $latencyMs,
        );
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0;
    }

    /**
     * Execute an agent via the host bridge HTTP server.
     */
    private function executeViaBridge(string $agentKey, array $config, AiRequestDTO $request): AiResponseDTO
    {
        $prompt = $this->buildPrompt($request);
        $timeout = config('local_agents.timeout', 300);
        $bridgeUrl = $this->discovery->bridgeUrl();
        $bridgeSecret = $this->discovery->bridgeSecret();

        Log::debug("LocalAgentGateway: executing {$agentKey} via bridge", [
            'bridge_url' => $bridgeUrl,
            'prompt_length' => strlen($prompt),
        ]);

        // Pre-flight: quick health check to fail fast if bridge is down/stuck
        try {
            $health = Http::timeout(5)
                ->connectTimeout(3)
                ->get($bridgeUrl . '/health');

            if (! $health->successful()) {
                throw new RuntimeException(
                    "Bridge health check failed (HTTP {$health->status()}). Is the bridge running?"
                );
            }
        } catch (RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Bridge unreachable at {$bridgeUrl} — start it with: ./docker/start-bridge.sh"
            );
        }

        $startTime = hrtime(true);

        try {
            $response = Http::timeout($timeout + 10)
                ->connectTimeout(config('local_agents.bridge.connect_timeout', 5))
                ->withToken($bridgeSecret)
                ->post($bridgeUrl . '/execute', [
                    'agent_key' => $agentKey,
                    'prompt' => $prompt,
                    'timeout' => $timeout,
                    'working_directory' => config('local_agents.working_directory'),
                ]);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                "Bridge connection failed for '{$config['name']}': " . $e->getMessage()
            );
        }

        $latencyMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

        $data = $response->json();

        if (! $response->successful() || ! ($data['success'] ?? false)) {
            $error = $data['error'] ?? $data['stderr'] ?? 'Unknown bridge error';
            $exitCode = $data['exit_code'] ?? $response->status();

            Log::error("LocalAgentGateway: bridge execution failed for {$agentKey}", [
                'exit_code' => $exitCode,
                'error' => substr($error, 0, 1000),
            ]);

            throw new RuntimeException(
                "Local agent '{$config['name']}' failed via bridge (code {$exitCode}): "
                . substr($error, 0, 500)
            );
        }

        $output = $data['output'] ?? '';
        $parsed = $this->parseOutput($agentKey, $output);

        return new AiResponseDTO(
            content: $parsed['content'],
            parsedOutput: $parsed['structured'],
            usage: new AiUsageDTO(
                promptTokens: $this->estimateTokens($prompt),
                completionTokens: $this->estimateTokens($parsed['content']),
                costCredits: 0,
            ),
            provider: $request->provider,
            model: $request->model,
            latencyMs: $data['execution_time_ms'] ?? $latencyMs,
        );
    }

    private function buildPrompt(AiRequestDTO $request): string
    {
        $parts = [];

        if ($request->systemPrompt) {
            $parts[] = $request->systemPrompt;
        }

        $parts[] = $request->userPrompt;

        return implode("\n\n", $parts);
    }

    private function buildCommand(string $agentKey, string $binaryPath, ?string $model = null): string
    {
        $modelFlag = $model ? ' --model ' . escapeshellarg($model) : '';

        return match ($agentKey) {
            'codex' => "{$binaryPath} exec --json --full-auto{$modelFlag}",
            'claude-code' => "{$binaryPath} --print --output-format json --dangerously-skip-permissions{$modelFlag}",
            default => throw new RuntimeException("No command template for agent: {$agentKey}"),
        };
    }

    /**
     * Parse output from the local agent CLI.
     *
     * @return array{content: string, structured: array|null}
     */
    private function parseOutput(string $agentKey, string $rawOutput): array
    {
        $rawOutput = trim($rawOutput);

        if (empty($rawOutput)) {
            return ['content' => '', 'structured' => null];
        }

        // Try JSON parse first (single JSON object)
        $json = json_decode($rawOutput, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $this->extractFromJson($agentKey, $json);
        }

        // JSONL: parse all lines and extract content from the event stream.
        // Codex `exec --json` outputs JSONL events. The actual response text
        // lives in `item.completed` events where item.type === "agent_message".
        // The last line is typically `turn.completed` which only has usage stats.
        $lines = explode("\n", $rawOutput);
        $agentMessages = [];
        $allEvents = [];
        $usageEvent = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
                continue;
            }

            $allEvents[] = $decoded;
            $eventType = $decoded['type'] ?? '';

            // Codex: collect agent_message text from item.completed events
            if ($eventType === 'item.completed'
                && ($decoded['item']['type'] ?? '') === 'agent_message'
                && isset($decoded['item']['text'])) {
                $agentMessages[] = $decoded['item']['text'];
            }

            // Codex: capture usage from turn.completed
            if ($eventType === 'turn.completed') {
                $usageEvent = $decoded;
            }
        }

        // If we found agent messages in the event stream, use them
        if (! empty($agentMessages)) {
            $content = implode("\n\n", $agentMessages);

            return [
                'content' => $content,
                'structured' => [
                    'type' => 'result',
                    'result' => $content,
                    'usage' => $usageEvent['usage'] ?? null,
                ],
            ];
        }

        // Fallback: use the last valid JSON event (for Claude Code or other formats)
        $lastEvent = end($allEvents) ?: null;

        if ($lastEvent) {
            return $this->extractFromJson($agentKey, $lastEvent);
        }

        // Raw text fallback
        return ['content' => $rawOutput, 'structured' => null];
    }

    /**
     * Extract content from a parsed JSON result.
     *
     * @return array{content: string, structured: array|null}
     */
    private function extractFromJson(string $agentKey, array $json): array
    {
        // Claude Code JSON output: { "result": "...", "cost_usd": 0.01, ... }
        // Or it may be an array of message objects
        if (isset($json['result'])) {
            return [
                'content' => is_string($json['result']) ? $json['result'] : json_encode($json['result']),
                'structured' => $json,
            ];
        }

        // Codex may output events; look for the last message with content
        if (isset($json['content'])) {
            return [
                'content' => is_string($json['content']) ? $json['content'] : json_encode($json['content']),
                'structured' => $json,
            ];
        }

        // If it's an array of messages, get the last assistant message
        if (isset($json[0]) && is_array($json[0])) {
            $assistantMessages = array_filter($json, fn ($m) => ($m['role'] ?? '') === 'assistant');
            $last = end($assistantMessages);

            if ($last && isset($last['content'])) {
                $content = is_array($last['content'])
                    ? collect($last['content'])->where('type', 'text')->pluck('text')->implode("\n")
                    : $last['content'];

                return ['content' => $content, 'structured' => $json];
            }
        }

        // Generic fallback: stringify the whole thing
        return [
            'content' => json_encode($json, JSON_PRETTY_PRINT),
            'structured' => $json,
        ];
    }

    /**
     * Resolve the local_agents.php agent key from the llm_providers config.
     * If the generic "local" provider is used, resolve to the first available local agent.
     */
    private function resolveAgentKey(string $provider): string
    {
        if ($provider === 'local') {
            $detected = $this->discovery->detect();
            if (empty($detected)) {
                throw new RuntimeException(
                    'No local agents detected. Install Codex or Claude Code CLI.'
                );
            }

            return array_key_first($detected);
        }

        return config("llm_providers.{$provider}.agent_key", $provider);
    }

    /**
     * Rough token estimate (4 chars per token).
     */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }
}
