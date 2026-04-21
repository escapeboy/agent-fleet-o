<?php

namespace App\Domain\Signal\Actions;

use App\Domain\Signal\Models\Entity;
use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Support\LlmDefaults;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExtractEntitiesAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Extract named entities from a signal and link them.
     *
     * @return Entity[]
     */
    public function execute(Signal $signal): array
    {
        $text = $this->buildTextFromSignal($signal);
        if (mb_strlen($text) < 20) {
            return [];
        }

        $teamId = $signal->team_id;
        $llm = $this->resolveLlm($teamId);

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: 'Extract named entities from the following text. Return ONLY a valid JSON array (no markdown, no code fences) of objects with: type (one of: person, company, location, date, product, topic), name (the entity as mentioned), context_sentence (the sentence or phrase where it was found, max 200 chars), confidence (0.0-1.0). Maximum 20 entities. Be selective — only extract clearly identifiable entities.',
            userPrompt: mb_substr($text, 0, 8000),
            maxTokens: 1024,
            teamId: $teamId,
            purpose: 'entity_extraction',
            temperature: 0.2,
        );

        try {
            $response = $this->gateway->complete($request);
            $entities = $this->parseEntities($response->content ?? '');

            if (empty($entities)) {
                return [];
            }

            return $this->upsertEntities($entities, $signal, $teamId);
        } catch (\Throwable $e) {
            Log::warning('ExtractEntitiesAction: Extraction failed', [
                'signal_id' => $signal->id,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    private function buildTextFromSignal(Signal $signal): string
    {
        $payload = $signal->payload ?? [];
        $parts = array_filter([
            $payload['title'] ?? $payload['subject'] ?? $payload['summary'] ?? '',
            $payload['description'] ?? $payload['body'] ?? $payload['content'] ?? $payload['text'] ?? '',
        ]);

        return implode("\n\n", $parts);
    }

    private function resolveLlm(string $teamId): array
    {
        // Use a cost-effective model for entity extraction, preferring whatever provider has a key configured
        $provider = LlmDefaults::provider();
        $model = LlmDefaults::model();

        // Fallback: if the default provider has no API key, try alternatives
        $providerKeyMap = [
            'anthropic' => config('prism.providers.anthropic.api_key'),
            'openai' => config('prism.providers.openai.api_key'),
            'google' => config('prism.providers.google.api_key'),
        ];

        if (empty($providerKeyMap[$provider] ?? null)) {
            if (! empty($providerKeyMap['openai'])) {
                $provider = 'openai';
                $model = 'gpt-4o-mini';
            } elseif (! empty($providerKeyMap['anthropic'])) {
                $provider = 'anthropic';
                $model = 'claude-haiku-4-5-20251001';
            } elseif (! empty($providerKeyMap['google'])) {
                $provider = 'google';
                $model = 'gemini-2.5-flash';
            }
        }

        return ['provider' => $provider, 'model' => $model];
    }

    private function parseEntities(string $content): array
    {
        $content = trim($content);

        // Strip markdown code fences if present
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```\w*\n?/', '', $content);
            $content = preg_replace('/\n?```$/', '', $content);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return [];
        }

        // Handle {entities: [...]} wrapper
        if (isset($decoded['entities']) && is_array($decoded['entities'])) {
            $decoded = $decoded['entities'];
        }

        return array_filter($decoded, fn ($e) => isset($e['type'], $e['name']));
    }

    /**
     * @return Entity[]
     */
    private function upsertEntities(array $extractedEntities, Signal $signal, string $teamId): array
    {
        $result = [];
        $validTypes = ['person', 'company', 'location', 'date', 'product', 'topic'];

        foreach ($extractedEntities as $extracted) {
            $type = strtolower($extracted['type'] ?? '');
            if (! in_array($type, $validTypes, true)) {
                continue;
            }

            $name = trim($extracted['name'] ?? '');
            if (empty($name)) {
                continue;
            }

            $canonicalName = Str::lower(Str::ascii($name));

            $entity = Entity::where('team_id', $teamId)
                ->where('type', $type)
                ->where('canonical_name', $canonicalName)
                ->first();

            if ($entity) {
                $entity->increment('mention_count');
                $entity->update(['last_seen_at' => now()]);
            } else {
                $entity = Entity::create([
                    'team_id' => $teamId,
                    'type' => $type,
                    'name' => $name,
                    'canonical_name' => $canonicalName,
                    'metadata' => [],
                    'mention_count' => 1,
                    'first_seen_at' => now(),
                    'last_seen_at' => now(),
                ]);
            }

            // Link entity to signal via pivot
            $entity->signals()->syncWithoutDetaching([
                $signal->id => [
                    'context' => mb_substr($extracted['context_sentence'] ?? '', 0, 255),
                    'confidence' => min(1.0, max(0.0, (float) ($extracted['confidence'] ?? 1.0))),
                ],
            ]);

            $result[] = $entity;
        }

        return $result;
    }
}
