<?php

namespace App\Domain\Signal\Jobs;

use App\Domain\Shared\Models\Team;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ScoreSignalRelevanceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public readonly string $signalId,
    ) {
        $this->onQueue('metrics');
    }

    public function handle(AiGatewayInterface $gateway, ProviderResolver $resolver): void
    {
        $signal = Signal::withoutGlobalScopes()->find($this->signalId);

        if (! $signal) {
            return;
        }

        if ($signal->relevance_score !== null) {
            return;
        }

        $content = is_string($signal->payload)
            ? $signal->payload
            : json_encode($signal->payload ?? []);

        $team = $signal->team_id ? Team::withoutGlobalScopes()->find($signal->team_id) : null;
        $resolved = $resolver->resolve(team: $team);

        try {
            $response = $gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: 'You are a signal quality scorer. Respond only with valid JSON.',
                userPrompt: "Rate the relevance and quality of this signal for an AI agent to act on. Score 0.0 (noise/spam) to 1.0 (high-signal, actionable). Return JSON only: {\"score\": <float>, \"reason\": \"<string>\"}\n\nSignal:\n".$content,
                maxTokens: 256,
                teamId: $signal->team_id,
                userId: Team::ownerIdFor($signal->team_id),
                purpose: 'signal.relevance_score',
                temperature: 0.1,
            ));

            $json = $this->extractJson($response->content);

            if ($json && isset($json['score'])) {
                $signal->update([
                    'relevance_score' => max(0.0, min(1.0, (float) $json['score'])),
                    'relevance_scored_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('ScoreSignalRelevanceJob: failed to score signal', [
                'signal_id' => $this->signalId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function extractJson(string $raw): ?array
    {
        // Strip markdown fences
        $raw = preg_replace('/^```(?:json)?\s*/m', '', $raw);
        $raw = preg_replace('/\s*```$/m', '', $raw);

        // Balanced-brace scan
        $start = strpos($raw, '{');
        if ($start === false) {
            return null;
        }

        $depth = 0;
        $end = null;
        for ($i = $start; $i < strlen($raw); $i++) {
            if ($raw[$i] === '{') {
                $depth++;
            } elseif ($raw[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    $end = $i;
                    break;
                }
            }
        }

        if ($end === null) {
            return null;
        }

        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);

        return is_array($decoded) ? $decoded : null;
    }
}
