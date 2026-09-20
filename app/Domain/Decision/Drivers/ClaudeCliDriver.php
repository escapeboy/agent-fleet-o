<?php

namespace App\Domain\Decision\Drivers;

use App\Domain\Decision\Contracts\BatchDecisionModel;
use App\Domain\Decision\Contracts\DecisionModel;
use App\Domain\Decision\DTOs\DecisionResult;
use App\Domain\Decision\Exceptions\DecisionRequestException;
use App\Domain\Decision\Services\CredentialRedactor;
use App\Domain\Decision\Services\StructuredDecisionPrompt;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Baseline driver that runs the locally installed `claude -p` instead of going
 * through the platform AI gateway.
 *
 * Why a separate driver rather than a gateway provider: the cloud edition force
 * -disables local agents (CloudServiceProvider swaps in DisabledLocalAgentGateway),
 * and that is a deliberate security boundary. An eval is not a reason to open
 * it, so this path shells out on its own and never touches the gateway.
 *
 * It also sidesteps two things the gateway would impose on a measurement run:
 * the tenant-scoped llm_request_logs write, and the 60-requests-per-minute
 * per-provider budget the rest of the process shares.
 *
 * Billing note: `claude -p` runs on the CLI's own credentials — a subscription,
 * not the metered API key. `total_cost_usd` in the envelope is what the same
 * call would have listed for, not a charge.
 */
class ClaudeCliDriver implements BatchDecisionModel, DecisionModel
{
    public function __construct(
        private readonly string $binary,
        private readonly string $model,
        private readonly int $timeoutSeconds = 180,
        private readonly int $concurrency = 4,
    ) {}

    public function model(): string
    {
        return $this->model;
    }

    public function decide(array|string $state, array $questions): DecisionResult
    {
        $results = $this->decideBatch(['single' => ['state' => $state, 'questions' => $questions]]);
        $result = $results['single'] ?? null;

        if ($result instanceof Throwable) {
            throw $result;
        }

        if (! $result instanceof DecisionResult) {
            throw new DecisionRequestException('claude -p returned no result.');
        }

        return $result;
    }

    public function decideBatch(array $requests): array
    {
        $results = [];

        foreach (array_chunk($requests, max(1, $this->concurrency), true) as $chunk) {
            foreach ($this->runChunk($chunk) as $key => $result) {
                $results[$key] = $result;
            }
        }

        return $results;
    }

    /**
     * Starts every process in the chunk, then waits. One slow case holds up
     * only its own chunk, and a case that fails comes back as its Throwable so
     * the rest of the batch still lands.
     *
     * @param  array<string, array{state: mixed, questions: array<string, array<string, mixed>>}>  $chunk
     * @return array<string, DecisionResult|Throwable>
     */
    private function runChunk(array $chunk): array
    {
        $running = [];
        $results = [];

        foreach ($chunk as $key => $request) {
            try {
                $running[$key] = $this->start($request['state'], $request['questions']);
            } catch (Throwable $e) {
                $results[$key] = $e;
            }
        }

        foreach ($running as $key => [$process, $promptFile, $questions, $startedAt]) {
            try {
                $process->wait();
                $results[$key] = $this->interpret($process, $questions, $startedAt);
            } catch (Throwable $e) {
                $results[$key] = new DecisionRequestException(
                    CredentialRedactor::scrub('claude -p failed: '.$e->getMessage()),
                );
            } finally {
                @unlink($promptFile);
            }
        }

        return $results;
    }

    /**
     * @param  array<string, array<string, mixed>>  $questions
     * @return array{0: Process, 1: string, 2: array<string, array<string, mixed>>, 3: int}
     */
    private function start(mixed $state, array $questions): array
    {
        $promptFile = tempnam(sys_get_temp_dir(), 'jev-sys-').'.txt';

        if (file_put_contents($promptFile, StructuredDecisionPrompt::system($questions)) === false) {
            throw new DecisionRequestException('Could not write the system prompt file.');
        }

        $process = new Process([
            $this->binary,
            '-p',
            '--output-format', 'json',
            '--model', $this->model,
            // Replaces Claude Code's own harness prompt. Without this the model
            // is answering as a coding agent, which is not the baseline we want.
            '--system-prompt-file', $promptFile,
            // Keeps the per-machine preamble (cwd, git status) out of the
            // system prompt so the cached prefix is identical across cases.
            '--exclude-dynamic-system-prompt-sections',
            StructuredDecisionPrompt::user($state),
        ]);

        // Run from an empty directory. Claude Code loads the CLAUDE.md of its
        // working directory as memory, and this repo's is large — measured at
        // 28.8k input tokens per call from /var/www against 14.5k from an empty
        // directory, for a question that needs neither.
        $process->setWorkingDirectory($this->scratchDirectory());
        $process->setTimeout($this->timeoutSeconds);
        $process->start();

        return [$process, $promptFile, $questions, hrtime(true)];
    }

    private function scratchDirectory(): string
    {
        $path = sys_get_temp_dir().'/jev-cli-scratch';

        if (! is_dir($path)) {
            @mkdir($path, 0700, true);
        }

        return is_dir($path) ? $path : sys_get_temp_dir();
    }

    /**
     * @param  array<string, array<string, mixed>>  $questions
     */
    private function interpret(Process $process, array $questions, int $startedAt): DecisionResult
    {
        $wallMs = (int) round((hrtime(true) - $startedAt) / 1_000_000);

        if (! $process->isSuccessful()) {
            throw new DecisionRequestException(
                CredentialRedactor::scrub('claude -p exited '.$process->getExitCode().': '.mb_substr($process->getErrorOutput(), 0, 500)),
            );
        }

        $envelope = json_decode($process->getOutput(), true);

        if (! is_array($envelope)) {
            throw new DecisionRequestException('claude -p did not return a JSON envelope.');
        }

        if (($envelope['is_error'] ?? false) === true) {
            throw new DecisionRequestException(
                CredentialRedactor::scrub('claude -p reported an error: '.mb_substr((string) ($envelope['result'] ?? ''), 0, 500)),
            );
        }

        $parsed = StructuredDecisionPrompt::extractFirstJsonObject((string) ($envelope['result'] ?? ''));

        if ($parsed === null || ! isset($parsed['answers'])) {
            throw new DecisionRequestException('claude -p returned no parsable answers object.');
        }

        $usage = is_array($envelope['usage'] ?? null) ? $envelope['usage'] : [];

        return new DecisionResult(
            answers: StructuredDecisionPrompt::answers($parsed, $questions),
            model: $this->model,
            // Cache reads and cache writes are input tokens the call paid for;
            // counting only `input_tokens` would report a handful of tokens for
            // a request that actually carried tens of thousands.
            inputTokens: (int) ($usage['input_tokens'] ?? 0)
                + (int) ($usage['cache_read_input_tokens'] ?? 0)
                + (int) ($usage['cache_creation_input_tokens'] ?? 0),
            // The CLI reports the API time separately from its own startup, and
            // the API time is the comparable number.
            latencyMs: (int) ($envelope['duration_api_ms'] ?? $wallMs),
        );
    }
}
