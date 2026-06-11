<?php

namespace App\Infrastructure\AI\Jobs;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Models\ShadowComparison;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Runs the shadow leg of a sampled A/B comparison: re-issues the primary prompt
 * against a candidate model and records both legs. Never on the primary request
 * path — dispatched fire-and-forget by FallbackAiGateway after the primary
 * response is already returned. The shadow output is recorded, never served.
 */
class RunShadowComparisonJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        private readonly string $systemPrompt,
        private readonly string $userPrompt,
        private readonly int $maxTokens,
        private readonly float $temperature,
        private readonly ?string $teamId,
        private readonly ?string $purpose,
        private readonly string $primaryProvider,
        private readonly string $primaryModel,
        private readonly int $primaryLatencyMs,
        private readonly int $primaryCostCredits,
        private readonly string $primaryContent,
        private readonly string $shadowProvider,
        private readonly string $shadowModel,
        private readonly bool $storeSnippets,
        private readonly int $snippetChars,
    ) {}

    public function handle(AiGatewayInterface $gateway): void
    {
        $primaryHash = hash('xxh128', $this->primaryContent);

        $row = [
            'team_id' => $this->teamId,
            'purpose' => $this->purpose,
            'prompt_hash' => hash('xxh128', $this->systemPrompt.'|'.$this->userPrompt),
            'primary_provider' => $this->primaryProvider,
            'primary_model' => $this->primaryModel,
            'primary_latency_ms' => $this->primaryLatencyMs,
            'primary_cost_credits' => $this->primaryCostCredits,
            'primary_output_hash' => $primaryHash,
            'primary_output_chars' => mb_strlen($this->primaryContent),
            'shadow_provider' => $this->shadowProvider,
            'shadow_model' => $this->shadowModel,
            'primary_snippet' => $this->storeSnippets ? mb_substr($this->primaryContent, 0, $this->snippetChars) : null,
        ];

        try {
            // ':shadow' purpose suffix is the recursion guard — FallbackAiGateway
            // never mirrors a request whose purpose already ends in ':shadow'.
            $shadowRequest = new AiRequestDTO(
                provider: $this->shadowProvider,
                model: $this->shadowModel,
                systemPrompt: $this->systemPrompt,
                userPrompt: $this->userPrompt,
                maxTokens: $this->maxTokens,
                teamId: $this->teamId,
                purpose: ($this->purpose ?? 'shadow').':shadow',
                temperature: $this->temperature,
            );

            $response = $gateway->complete($shadowRequest);
            $shadowHash = hash('xxh128', $response->content);

            $row['shadow_status'] = 'completed';
            $row['shadow_latency_ms'] = $response->latencyMs;
            $row['shadow_cost_credits'] = $response->usage->costCredits;
            $row['shadow_output_hash'] = $shadowHash;
            $row['shadow_output_chars'] = mb_strlen($response->content);
            $row['outputs_match'] = $shadowHash === $primaryHash;
            $row['shadow_snippet'] = $this->storeSnippets ? mb_substr($response->content, 0, $this->snippetChars) : null;
        } catch (Throwable $e) {
            $row['shadow_status'] = 'failed';
            $row['shadow_error'] = mb_substr($e->getMessage(), 0, 500);

            Log::info('ShadowComparison: shadow leg failed', [
                'shadow_provider' => $this->shadowProvider,
                'shadow_model' => $this->shadowModel,
                'error' => $e->getMessage(),
            ]);
        }

        ShadowComparison::create($row);
    }
}
