<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Shared\Models\TeamProviderCredential;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use App\Infrastructure\RunPod\RunPodClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Executes a RunPod Pod lifecycle skill.
 *
 * Manages a complete GPU pod lifecycle within a single skill execution:
 *   1. Create pod with specified GPU / Docker image
 *   2. Poll until pod is RUNNING (up to startup_timeout_seconds)
 *   3. Send an HTTP request to the pod's proxied endpoint
 *   4. Stop the pod (always — even on failure)
 *   5. Record cost_credits = actual_elapsed_minutes × GPU_credits_per_minute
 *
 * Pod costs are billed directly to the user's RunPod account.
 * cost_credits reflects the estimated monetary cost for analytics only.
 *
 * Skill configuration keys:
 *   - image_name               (required) Docker image (e.g. 'runpod/pytorch:latest')
 *   - gpu_type_id              (required) GPU model (e.g. 'NVIDIA RTX 4090')
 *   - gpu_count                (int, default 1)
 *   - container_disk_gb        (int, default 20)
 *   - volume_in_gb             (int, optional)
 *   - ports                    (array, e.g. ['8080/http', '22/tcp'])
 *   - env                      (object, environment variables for the container)
 *   - interruptible            (bool, default false) use spot pricing
 *   - cloud_type               (string, 'SECURE'|'COMMUNITY', default 'COMMUNITY')
 *   - startup_timeout_seconds  (int, default 300) max wait for pod to be RUNNING
 *   - estimated_minutes        (int, default 10) used for cost tracking
 *   - request_url_template     (string, optional) URL to call; supports {pod_id}
 *   - request_method           (string, default 'POST')
 *   - request_headers          (object, optional extra headers)
 *   - request_timeout_seconds  (int, default 60)
 *
 * Input (at runtime):
 *   - Anything to forward as the HTTP request body to the pod endpoint.
 *   - If request_url_template is empty, only creates and stops the pod.
 */
class ExecuteRunPodPodSkillAction
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

        // Validate required configuration
        $imageName = $config['image_name'] ?? null;
        $gpuTypeId = $config['gpu_type_id'] ?? null;

        if (! $imageName) {
            return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input,
                'RunPod Pod skill missing required configuration: image_name.');
        }

        if (! $gpuTypeId) {
            return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input,
                'RunPod Pod skill missing required configuration: gpu_type_id.');
        }

        // Resolve API key
        $apiKey = $this->resolveApiKey($teamId);

        if (! $apiKey) {
            return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input,
                'No active RunPod API key configured. Add your RunPod API key in Team Settings → RunPod Integration.');
        }

        $startTime = hrtime(true);
        $podId = null;

        try {
            // Build pod creation payload
            $podPayload = $this->buildPodPayload($config, $skill->name);

            // Create the pod
            $pod = $this->client->createPod($podPayload, $apiKey);
            $podId = $pod['id'] ?? null;

            if (! $podId) {
                throw new \RuntimeException('RunPod did not return a pod ID after creation.');
            }

            Log::info('RunPodPodSkill: pod created', [
                'pod_id' => $podId,
                'skill_id' => $skill->id,
                'gpu_type' => $gpuTypeId,
            ]);

            // Wait for pod to become RUNNING
            $startupTimeout = (int) ($config['startup_timeout_seconds'] ?? config('runpod.pod_defaults.startup_timeout_seconds', 300));
            $this->waitForPodRunning($podId, $apiKey, $startupTimeout);

            // Optionally send HTTP request to the pod
            $output = ['pod_id' => $podId, 'status' => 'completed'];
            $requestUrlTemplate = $config['request_url_template'] ?? null;

            if ($requestUrlTemplate) {
                $requestUrl = str_replace('{pod_id}', $podId, $requestUrlTemplate);
                $output = $this->sendPodRequest($requestUrl, $input, $config);
                $output['pod_id'] = $podId;
            }

            // Always stop the pod
            $this->client->stopPod($podId, $apiKey);

            Log::info('RunPodPodSkill: pod stopped', ['pod_id' => $podId]);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $costCredits = $this->calculateCostCredits($gpuTypeId, $durationMs, (bool) ($config['interruptible'] ?? false));

            $execution = SkillExecution::create([
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $output,
                'duration_ms' => $durationMs,
                'cost_credits' => $costCredits, // Informational — billed directly to user's RunPod account
            ]);

            $skill->recordExecution(true, $durationMs);

            return ['execution' => $execution, 'output' => $output];
        } catch (\Throwable $e) {
            // Ensure pod is always stopped on failure
            if ($podId) {
                try {
                    $this->client->stopPod($podId, $apiKey);
                } catch (\Throwable $stopError) {
                    Log::warning('RunPodPodSkill: failed to stop pod after error', [
                        'pod_id' => $podId,
                        'stop_error' => $stopError->getMessage(),
                    ]);
                }
            }

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $skill->recordExecution(false, $durationMs);

            Log::warning('RunPodPodSkill: execution failed', [
                'pod_id' => $podId,
                'skill_id' => $skill->id,
                'error' => $e->getMessage(),
            ]);

            return $this->failExecution($skill, $teamId, $agentId, $experimentId, $input,
                $e->getMessage(), $durationMs);
        }
    }

    private function buildPodPayload(array $config, string $skillName): array
    {
        $defaults = config('runpod.pod_defaults', []);

        $payload = [
            'name' => 'agent-fleet-'.preg_replace('/[^a-z0-9-]/', '-', strtolower($skillName)).'-'.substr(uniqid(), -6),
            'imageName' => $config['image_name'],
            'gpuTypeIds' => [$config['gpu_type_id']],
            'gpuCount' => (int) ($config['gpu_count'] ?? $defaults['gpu_count'] ?? 1),
            'containerDiskInGb' => (int) ($config['container_disk_gb'] ?? $defaults['container_disk_gb'] ?? 20),
            'cloudType' => $config['cloud_type'] ?? 'COMMUNITY',
            'interruptible' => (bool) ($config['interruptible'] ?? false),
        ];

        if (! empty($config['volume_in_gb'])) {
            $payload['volumeInGb'] = (int) $config['volume_in_gb'];
        }

        if (! empty($config['ports'])) {
            $payload['ports'] = $config['ports'];
        }

        if (! empty($config['env']) && is_array($config['env'])) {
            $payload['env'] = $config['env'];
        }

        return $payload;
    }

    private function waitForPodRunning(string $podId, string $apiKey, int $timeoutSeconds): void
    {
        $deadline = time() + $timeoutSeconds;
        $pollInterval = (int) config('runpod.pod_defaults.poll_interval_seconds', 10);

        while (time() < $deadline) {
            sleep($pollInterval);

            $pod = $this->client->getPod($podId, $apiKey);
            $desiredStatus = $pod['desiredStatus'] ?? 'UNKNOWN';

            if ($desiredStatus === 'RUNNING' && ! empty($pod['runtime'])) {
                return; // Pod is ready
            }

            if ($desiredStatus === 'EXITED') {
                throw new \RuntimeException(
                    "RunPod pod {$podId} exited unexpectedly before becoming RUNNING."
                );
            }
        }

        throw new \RuntimeException(
            "RunPod pod {$podId} did not become RUNNING within {$timeoutSeconds}s."
        );
    }

    private function sendPodRequest(string $url, array $input, array $config): array
    {
        $method = strtoupper($config['request_method'] ?? 'POST');
        $headers = is_array($config['request_headers'] ?? null) ? $config['request_headers'] : [];
        $requestTimeout = (int) ($config['request_timeout_seconds'] ?? 60);

        $http = Http::timeout($requestTimeout)->withHeaders($headers);

        $response = match ($method) {
            'POST' => $http->post($url, $input),
            'PUT' => $http->put($url, $input),
            'GET' => $http->get($url, $input),
            default => $http->post($url, $input),
        };

        if (! $response->successful()) {
            throw new \RuntimeException(
                "Pod HTTP request failed [{$response->status()}]: ".mb_substr($response->body(), 0, 500)
            );
        }

        $body = $response->json();

        return is_array($body) ? $body : ['response' => $response->body()];
    }

    /**
     * Calculate cost in platform credits for analytics/reporting.
     * Costs are NOT enforced (billed directly to user's RunPod account).
     * 1 credit = $0.001 USD.
     */
    private function calculateCostCredits(string $gpuTypeId, int $durationMs, bool $interruptible): int
    {
        $gpuPrices = config('runpod.gpu_credits_per_hour', []);
        $creditsPerHour = $gpuPrices[$gpuTypeId] ?? $gpuPrices['default'] ?? 500;

        if ($interruptible) {
            $spotDiscount = (float) config('runpod.spot_discount', 0.4);
            $creditsPerHour = (int) ($creditsPerHour * $spotDiscount);
        }

        $durationHours = $durationMs / 3_600_000;

        return (int) round($creditsPerHour * $durationHours);
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
