<?php

namespace App\Infrastructure\AI\Gateways;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\DTOs\AiResponseDTO;
use App\Infrastructure\AI\DTOs\AiUsageDTO;
use App\Infrastructure\AI\Services\LocalAgentDiscovery;
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
        $agentKey = $request->model;
        $config = config("local_agents.agents.{$agentKey}");

        if (! $config) {
            throw new RuntimeException("Unknown local agent: {$agentKey}");
        }

        $binaryPath = $this->discovery->binaryPath($agentKey);

        if (! $binaryPath) {
            throw new RuntimeException("Local agent '{$config['name']}' is not available on this system.");
        }

        $prompt = $this->buildPrompt($request);
        $command = $this->buildCommand($agentKey, $binaryPath);
        $timeout = config('local_agents.timeout', 300);
        $workdir = config('local_agents.working_directory') ?? base_path();

        Log::debug("LocalAgentGateway: executing {$agentKey}", [
            'command' => $command,
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
            provider: 'local',
            model: $agentKey,
            latencyMs: $latencyMs,
        );
    }

    public function estimateCost(AiRequestDTO $request): int
    {
        return 0;
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

    private function buildCommand(string $agentKey, string $binaryPath): string
    {
        return match ($agentKey) {
            'codex' => "{$binaryPath} --quiet --output-format json --approval-mode full-auto",
            'claude-code' => "{$binaryPath} --print --output-format json --dangerously-skip-permissions",
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

        // Try JSON parse first
        $json = json_decode($rawOutput, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            return $this->extractFromJson($agentKey, $json);
        }

        // Try line-by-line JSONL (Codex outputs JSONL events)
        $lines = explode("\n", $rawOutput);
        $lastResult = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $decoded = json_decode($line, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $lastResult = $decoded;
            }
        }

        if ($lastResult) {
            return $this->extractFromJson($agentKey, $lastResult);
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
     * Rough token estimate (4 chars per token).
     */
    private function estimateTokens(string $text): int
    {
        return (int) ceil(strlen($text) / 4);
    }
}
