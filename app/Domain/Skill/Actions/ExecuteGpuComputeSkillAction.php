<?php

namespace App\Domain\Skill\Actions;

use App\Infrastructure\Compute\ComputeProviderManager;
use App\Infrastructure\Compute\Contracts\ComputeProviderInterface;
use App\Infrastructure\Compute\DTOs\ComputeJobDTO;
use App\Infrastructure\Compute\DTOs\ComputeJobResultDTO;
use App\Infrastructure\Compute\Services\ComputeCredentialResolver;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Support\Facades\Log;

/**
 * Executes a GpuCompute skill using the pluggable compute provider system.
 *
 * Routes to any registered provider (RunPod, Replicate, Fal, Modal, Vast)
 * based on skill configuration.provider. Costs are billed directly to the
 * user's provider account — no platform credits are consumed.
 *
 * Skill configuration keys:
 *   - provider          (string, default 'runpod') compute provider slug
 *   - endpoint_id       (required) provider endpoint/model identifier
 *   - use_sync          (bool, default true) use synchronous execution when supported
 *   - timeout_seconds   (int, default 90) max wait time
 *   - input_mapping     (object, optional) maps skill input keys → endpoint input keys
 */
class ExecuteGpuComputeSkillAction
{
    public function __construct(
        private readonly ComputeProviderManager $manager,
        private readonly ComputeCredentialResolver $credentialResolver,
    ) {}

    /**
     * @return array{execution: SkillExecution, output: array|null}
     */
    public function execute(
        Skill $skill,
        array $input,
        string $teamId,
        string $userId,
        ?string $agentId = null,
        ?string $experimentId = null,
    ): array {
        $config = is_array($skill->configuration) ? $skill->configuration : [];
        $provider = $config['provider'] ?? 'runpod';
        $endpointId = $config['endpoint_id'] ?? null;

        if (! $endpointId) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                "GpuCompute skill missing required configuration: endpoint_id.",
            );
        }

        try {
            $credentials = $this->credentialResolver->resolveOrFail($teamId, $provider);
        } catch (\RuntimeException $e) {
            return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input, $e->getMessage());
        }

        $useSync = (bool) ($config['use_sync'] ?? true);
        $timeout = (int) ($config['timeout_seconds'] ?? 90);
        $inputMapping = is_array($config['input_mapping'] ?? null) ? $config['input_mapping'] : [];

        $job = new ComputeJobDTO(
            provider: $provider,
            endpointId: $endpointId,
            input: $input,
            credentials: $credentials,
            timeoutSeconds: $timeout,
            useSync: $useSync,
            inputMapping: $inputMapping,
            options: $config,
        );

        $providerInstance = $this->manager->driver($provider);
        $startTime = hrtime(true);

        try {
            $result = $useSync
                ? $providerInstance->runSync($job)
                : $this->runAsync($providerInstance, $job, $timeout);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            if ($result->isFailed()) {
                throw new \RuntimeException('Compute job failed: '.($result->error ?? 'Unknown error'));
            }

            $execution = SkillExecution::create([
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $result->output,
                'duration_ms' => $durationMs,
                'cost_credits' => 0, // Billed directly to user's provider account
            ]);

            $skill->recordExecution(true, $durationMs);

            return ['execution' => $execution, 'output' => $result->output];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $skill->recordExecution(false, $durationMs);

            Log::warning('ExecuteGpuComputeSkillAction failed', [
                'provider' => $provider,
                'endpoint_id' => $endpointId,
                'skill_id' => $skill->id,
                'error' => $e->getMessage(),
            ]);

            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                $e->getMessage(), $durationMs,
            );
        }
    }

    private function runAsync(
        ComputeProviderInterface $provider,
        ComputeJobDTO $job,
        int $timeoutSeconds,
    ): ComputeJobResultDTO {
        $jobId = $provider->submit($job);
        $deadline = time() + $timeoutSeconds;
        $delay = 2;

        while (time() < $deadline) {
            sleep($delay);
            $delay = min($delay * 2, 10); // exponential back-off, max 10 s

            $result = $provider->getResult($jobId, $job);

            if ($result->isTerminal()) {
                return $result;
            }
        }

        $provider->cancel($jobId, $job);

        throw new \RuntimeException("Compute job {$jobId} timed out after {$timeoutSeconds}s.");
    }

    /**
     * @return array{execution: SkillExecution, output: null}
     */
    private function failExecution(
        Skill $skill,
        string $teamId,
        ?string $agentId,
        ?string $experimentId,
        array $input,
        string $errorMessage,
        int $durationMs = 0,
    ): array {
        $execution = SkillExecution::create([
            'skill_id' => $skill->id,
            'agent_id' => $agentId,
            'experiment_id' => $experimentId,
            'team_id' => $teamId,
            'status' => 'failed',
            'input' => $input,
            'output' => null,
            'duration_ms' => $durationMs,
            'cost_credits' => 0,
            'error_message' => $errorMessage,
        ]);

        return ['execution' => $execution, 'output' => null];
    }
}
