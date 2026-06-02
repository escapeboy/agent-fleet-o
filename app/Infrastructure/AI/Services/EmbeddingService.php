<?php

namespace App\Infrastructure\AI\Services;

use App\Domain\Shared\Models\TeamProviderCredential;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;

class EmbeddingService
{
    public function __construct(
        private readonly string $provider = 'openai',
        private readonly string $model = 'text-embedding-3-small',
    ) {}

    /**
     * Generate an embedding vector for the given text.
     *
     * @return float[]
     */
    public function embed(string $text): array
    {
        $response = Prism::embeddings()
            ->using($this->provider, $this->model)
            ->fromInput($this->truncateToEmbeddingLimit($text))
            ->asEmbeddings();

        return $response->embeddings[0]->embedding;
    }

    /**
     * Truncate embedding input to the model's token limit. OpenAI's
     * text-embedding-3-* models reject inputs over 8192 tokens with a 400
     * ("maximum input length is 8192 tokens"); a long input (e.g. a whole pasted
     * document) would otherwise fail the embedding call. We estimate tokens via
     * TokenEstimator's chars-per-token ratio and cut on a character budget,
     * leaving a small safety margin so the estimate never undershoots the real
     * tokenizer.
     */
    private function truncateToEmbeddingLimit(string $text): string
    {
        $maxTokens = (int) config('memory.embedding_max_input_tokens', 8192);

        $estimator = app(TokenEstimator::class);
        if ($estimator->estimate($text) <= $maxTokens) {
            return $text;
        }

        // 4 chars/token matches TokenEstimator::CHARS_PER_TOKEN; 0.95 margin keeps
        // the truncated string safely under the hard token cap.
        $charBudget = (int) floor($maxTokens * 4 * 0.95);

        return mb_substr($text, 0, $charBudget);
    }

    /**
     * Team-aware variant. Resolves a usable API key in order:
     *   1. Team's BYOK TeamProviderCredential
     *   2. Platform key (services.platform_api_keys.{provider})
     *   3. Env-driven legacy fallback (services.{provider}.key)
     *
     * Returns null when no key is reachable so callers can degrade
     * gracefully instead of letting a 401 bubble up. The first two
     * paths mirror PrismAiGateway::applyTeamCredentials so BYOK
     * embeddings now work the same way BYOK chat does.
     *
     * @return float[]|null
     */
    public function embedForTeam(string $text, ?string $teamId): ?array
    {
        $configKey = $this->prismConfigKeyFor($this->provider);
        if (! $configKey) {
            return $this->safeEmbed($text);
        }

        $applied = false;
        $previousKey = config($configKey);

        if ($teamId) {
            $credential = TeamProviderCredential::where('team_id', $teamId)
                ->where('provider', $this->provider)
                ->where('is_active', true)
                ->first();

            if ($credential && ! empty($credential->credentials['api_key'])) {
                config([$configKey => $credential->credentials['api_key']]);
                $applied = true;
            }
        }

        if (! $applied) {
            $platformKey = config("services.platform_api_keys.{$this->provider}");
            if (is_string($platformKey) && $platformKey !== '') {
                config([$configKey => $platformKey]);
                $applied = true;
            }
        }

        // If neither team BYOK nor platform key was applied AND the existing
        // Prism config has no usable key, skip the call instead of letting
        // Prism throw a 401 — caller is expected to handle null gracefully.
        if (! $applied && ! is_string(config($configKey)) || config($configKey) === '') {
            return null;
        }

        try {
            return $this->embed($text);
        } catch (\Throwable $e) {
            Log::debug('EmbeddingService: embed failed', [
                'provider' => $this->provider,
                'model' => $this->model,
                'team_id' => $teamId,
                'error' => $e->getMessage(),
            ]);

            return null;
        } finally {
            if ($applied) {
                config([$configKey => $previousKey]);
            }
        }
    }

    /**
     * Format a float[] embedding as a pgvector literal string, e.g. "[0.1,0.2,...]".
     *
     * @param  float[]  $embedding
     */
    public function formatForPgvector(array $embedding): string
    {
        return '['.implode(',', $embedding).']';
    }

    /**
     * @return float[]|null
     */
    private function safeEmbed(string $text): ?array
    {
        try {
            return $this->embed($text);
        } catch (\Throwable $e) {
            Log::debug('EmbeddingService: embed failed (no team scope)', [
                'provider' => $this->provider,
                'model' => $this->model,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function prismConfigKeyFor(string $provider): ?string
    {
        return match ($provider) {
            'openai' => 'prism.providers.openai.api_key',
            'anthropic' => 'prism.providers.anthropic.api_key',
            'google' => 'prism.providers.gemini.api_key',
            'voyage' => 'prism.providers.voyage.api_key',
            default => null,
        };
    }
}
