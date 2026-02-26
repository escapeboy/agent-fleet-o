<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Infrastructure\RunPod\RunPodClient;
use Illuminate\Support\Facades\Log;

/**
 * Executes a RunPod Serverless Endpoint skill.
 *
 * Calls RunPod's /runsync (synchronous) or /run + poll (async) endpoint.
 * Costs are billed directly to the user's RunPod account — no platform credits consumed.
 *
 * Skill configuration keys:
 *   - endpoint_id        (required) RunPod serverless endpoint ID
 *   - use_sync           (bool, default true) use /runsync vs /run+poll
 *   - timeout_seconds    (int, default 90) max wait time in seconds
 *   - input_mapping      (object, optional) maps skill input keys → endpoint input keys
 *
 * Requires a RunPod API key saved in TeamProviderCredential with provider = 'runpod'.
 */
class ExecuteRunPodSkillAction
{
    public function __construct(
        private readonly RunPodClient $client,
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
        $endpointId = $config['endpoint_id'] ?? null;

        if (! $endpointId) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'RunPod skill missing required configuration: endpoint_id.',
            );
        }

        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'No active RunPod API key configured for this team. Add your RunPod API key in Team Settings → RunPod Integration.',
            );
        }

        $endpointInput = $this->applyInputMapping($input, $config['input_mapping'] ?? []);
        $useSync = (bool) ($config['use_sync'] ?? true);
        $timeout = (int) ($config['timeout_seconds'] ?? 90);

        $startTime = hrtime(true);

        try {
            $output = $useSync
                ? $this->runSynchronous($endpointId, $endpointInput, $apiKey, $timeout)
                : $this->runAsynchronous($endpointId, $endpointInput, $apiKey, $timeout);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            $execution = SkillExecution::create([
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $output,
                'duration_ms' => $durationMs,
                'cost_credits' => 0, // Billed directly to user's RunPod account
            ]);

            $skill->recordExecution(true, $durationMs);

            return ['execution' => $execution, 'output' => $output];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $skill->recordExecution(false, $durationMs);

            Log::warning('ExecuteRunPodSkillAction failed', [
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

    private function resolveApiKey(string $teamId): ?string
    {
        $credential = TeamProviderCredential::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('provider', 'runpod')
            ->where('is_active', true)
            ->first();

        return $credential?->credentials['api_key'] ?? null;
    }

    private function runSynchronous(string $endpointId, array $input, string $apiKey, int $timeout): array
    {
        $result = $this->client->runSync($endpointId, $input, $apiKey, $timeout);

        return $this->extractOutput($result);
    }

    private function runAsynchronous(string $endpointId, array $input, string $apiKey, int $timeout): array
    {
        $submitted = $this->client->run($endpointId, $input, $apiKey);
        $jobId = $submitted['id'] ?? null;

        if (! $jobId) {
            throw new \RuntimeException('RunPod async run did not return a job ID.');
        }

        return $this->pollUntilComplete($endpointId, $jobId, $apiKey, $timeout);
    }

    private function pollUntilComplete(string $endpointId, string $jobId, string $apiKey, int $timeoutSeconds): array
    {
        $deadline = time() + $timeoutSeconds;
        $delay = 2;

        while (time() < $deadline) {
            sleep($delay);
            $delay = min($delay * 2, 10); // exponential back-off, max 10 s

            $status = $this->client->getStatus($endpointId, $jobId, $apiKey);
            $jobStatus = $status['status'] ?? 'UNKNOWN';

            if ($jobStatus === 'COMPLETED') {
                return $this->extractOutput($status);
            }

            if (in_array($jobStatus, ['FAILED', 'CANCELLED', 'TIMED_OUT'], true)) {
                throw new \RuntimeException(
                    "RunPod job {$jobId} ended with status: {$jobStatus}. Error: ".($status['error'] ?? 'Unknown'),
                );
            }
        }

        throw new \RuntimeException("RunPod job {$jobId} timed out after {$timeoutSeconds}s.");
    }

    /**
     * Apply optional key remapping between skill input and endpoint input.
     *
     * input_mapping format: {"endpoint_key": "skill_input_key"}
     * Unmapped skill keys are passed through as-is.
     */
    private function applyInputMapping(array $input, array $mapping): array
    {
        if (empty($mapping)) {
            return $input;
        }

        $mapped = [];
        $consumedInputKeys = [];

        foreach ($mapping as $endpointKey => $inputKey) {
            if (array_key_exists($inputKey, $input)) {
                $mapped[$endpointKey] = $input[$inputKey];
                $consumedInputKeys[] = $inputKey;
            }
        }

        // Pass through any unmapped input keys
        foreach ($input as $key => $value) {
            if (! in_array($key, $consumedInputKeys, true)) {
                $mapped[$key] = $value;
            }
        }

        return $mapped;
    }

    /**
     * Normalize the RunPod result envelope to a plain output array.
     */
    private function extractOutput(array $result): array
    {
        $output = $result['output'] ?? $result;

        if (is_string($output)) {
            return ['output' => $output];
        }

        return is_array($output) ? $output : ['output' => $output];
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
