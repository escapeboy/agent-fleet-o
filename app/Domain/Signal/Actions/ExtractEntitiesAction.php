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
            systemPrompt: 'Analyze the following text and return ONLY a valid JSON object (no markdown, no code fences) with two keys: "entities" — an array of objects with type (one of: person, company, location, date, product, topic), name (the entity as mentioned), context_sentence (the sentence or phrase where it was found, max 200 chars), confidence (0.0-1.0); maximum 20 entities, be selective and only extract clearly identifiable entities. "novelty" — an integer from 1 to 5 rating how novel and non-routine this signal is (1 = routine, boilerplate, or near-duplicate of common chatter; 5 = highly novel, surprising, or unprecedented).',
            userPrompt: mb_substr($text, 0, 8000),
            maxTokens: 1024,
            teamId: $teamId,
            purpose: 'entity_extraction',
            temperature: 0.2,
        );

        try {
            $response = $this->gateway->complete($request);
            $parsed = $this->parseEnrichment($response->content ?? '');

            $this->persistNovelty($signal, $parsed['novelty']);

            if (empty($parsed['entities'])) {
                return [];
            }

            return $this->upsertEntities($parsed['entities'], $signal, $teamId);
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

    /**
     * @return array{entities: array<int, array<string, mixed>>, novelty: int|null}
     */
    private function parseEnrichment(string $content): array
    {
        $content = trim($content);

        // Strip markdown code fences if present
        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```\w*\n?/', '', $content);
            $content = preg_replace('/\n?```$/', '', $content);
        }

        $decoded = json_decode($content, true);

        if (! is_array($decoded)) {
            return ['entities' => [], 'novelty' => null];
        }

        $novelty = null;
        if (isset($decoded['novelty']) && is_numeric($decoded['novelty'])) {
            $novelty = (int) max(1, min(5, (int) $decoded['novelty']));
        }

        // Entities may be the top-level array or wrapped under an "entities" key.
        $entities = (isset($decoded['entities']) && is_array($decoded['entities']))
            ? $decoded['entities']
            : $decoded;

        $entities = array_values(array_filter(
            $entities,
            fn ($e) => is_array($e) && isset($e['type'], $e['name']),
        ));

        return ['entities' => $entities, 'novelty' => $novelty];
    }

    private function persistNovelty(Signal $signal, ?int $novelty): void
    {
        if ($novelty === null) {
            return;
        }

        $metadata = $signal->metadata ?? [];
        $metadata['novelty'] = $novelty;
        $metadata['novelty_at'] = now()->toIso8601String();
        $signal->metadata = $metadata;
        $signal->save();
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
