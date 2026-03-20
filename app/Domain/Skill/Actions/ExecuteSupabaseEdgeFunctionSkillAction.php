<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Credential\Models\Credential;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillExecution;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Executes a Supabase Edge Function skill.
 *
 * Calls a Supabase Edge Function via the Supabase REST API.
 * Costs are billed directly to the user's Supabase account — no platform credits consumed.
 *
 * Skill configuration keys:
 *   - project_url        (required) Supabase project URL, e.g. https://xyzabcdef.supabase.co
 *   - function_name      (required) Edge function name (slug), e.g. process-data
 *   - credential_id      (required) UUID of a Credential with the service role key
 *   - timeout_seconds    (int, default 60) max wait time in seconds
 *   - method             (string, default POST) HTTP method to use
 *
 * The credential must be of type api_key with secret_data['key'] = service_role_key.
 *
 * @see https://supabase.com/docs/guides/functions/invoke-edge-functions
 */
class ExecuteSupabaseEdgeFunctionSkillAction
{
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
        $projectUrl = rtrim($config['project_url'] ?? '', '/');
        $functionName = $config['function_name'] ?? null;
        $credentialId = $config['credential_id'] ?? null;

        if (! $projectUrl || ! $functionName) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'Supabase Edge Function skill missing required configuration: project_url and function_name.',
            );
        }

        $serviceRoleKey = $this->resolveServiceRoleKey($credentialId, $teamId);

        if (! $serviceRoleKey) {
            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                'No Supabase service role key found. Set credential_id in skill configuration to a Credential containing your service_role_key.',
            );
        }

        $timeout = (int) ($config['timeout_seconds'] ?? 60);
        $method = strtoupper($config['method'] ?? 'POST');
        $url = "{$projectUrl}/functions/v1/{$functionName}";

        $startTime = hrtime(true);

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$serviceRoleKey}",
                'Content-Type' => 'application/json',
            ])
                ->timeout($timeout)
                ->send($method, $url, ['json' => $input]);

            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);

            if (! $response->successful()) {
                $errorBody = $response->body();

                Log::warning('ExecuteSupabaseEdgeFunctionSkillAction HTTP error', [
                    'url' => $url,
                    'status' => $response->status(),
                    'skill_id' => $skill->id,
                    'error' => $errorBody,
                ]);

                return $this->failExecution(
                    $skill, $teamId, $agentId, $experimentId, $input,
                    "Edge function returned HTTP {$response->status()}: {$errorBody}",
                    $durationMs,
                );
            }

            $output = $response->json() ?? ['raw' => $response->body()];

            $execution = SkillExecution::create([
                'skill_id' => $skill->id,
                'agent_id' => $agentId,
                'experiment_id' => $experimentId,
                'team_id' => $teamId,
                'status' => 'completed',
                'input' => $input,
                'output' => $output,
                'duration_ms' => $durationMs,
                'cost_credits' => 0, // Billed directly to user's Supabase account
            ]);

            $skill->recordExecution(true, $durationMs);

            return ['execution' => $execution, 'output' => $output];
        } catch (\Throwable $e) {
            $durationMs = (int) ((hrtime(true) - $startTime) / 1_000_000);
            $skill->recordExecution(false, $durationMs);

            Log::warning('ExecuteSupabaseEdgeFunctionSkillAction failed', [
                'url' => $url,
                'skill_id' => $skill->id,
                'error' => $e->getMessage(),
            ]);

            return $this->failExecution(
                $skill, $teamId, $agentId, $experimentId, $input,
                $e->getMessage(), $durationMs,
            );
        }
    }

    private function resolveServiceRoleKey(?string $credentialId, string $teamId): ?string
    {
        if (! $credentialId) {
            return null;
        }

        $credential = Credential::withoutGlobalScopes()
            ->where('id', $credentialId)
            ->where('team_id', $teamId)
            ->where('status', 'active')
            ->first();

        return $credential?->secret_data['key'] ?? null;
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
