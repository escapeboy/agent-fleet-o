<?php

namespace App\Domain\Signal\Actions;

use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use Illuminate\Support\Facades\Log;

class StructureSignalWithAiAction
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
    ) {}

    /**
     * Take a free-text description and return a structured signal payload.
     *
     * @return array{title: string, description: string, priority: string, tags: string[], source_type: string, metadata: array<string, mixed>}
     */
    public function execute(string $rawText, string $teamId): array
    {
        if (mb_strlen(trim($rawText)) < 5) {
            return $this->defaultPayload($rawText);
        }

        $llm = $this->resolveLlm();

        $systemPrompt = <<<'PROMPT'
You are a request intake assistant. Convert the user's free-text description into a structured intake record.

Return ONLY valid JSON (no markdown, no code fences) with these fields:
- title: string (concise, ≤ 80 chars)
- description: string (cleaned-up version of the original text)
- priority: one of "low", "medium", "high", "critical"
- tags: array of relevant lowercase tags (max 5, single words or hyphenated)
- source_type: one of "manual", "bug_report", "feature_request", "support_ticket", "feedback", "alert", "incident", "task"
- metadata: object with any relevant structured fields extracted from the text (e.g. affected_user, url, version, component, steps_to_reproduce)
PROMPT;

        $request = new AiRequestDTO(
            provider: $llm['provider'],
            model: $llm['model'],
            systemPrompt: $systemPrompt,
            userPrompt: mb_substr($rawText, 0, 4000),
            maxTokens: 512,
            teamId: $teamId,
            purpose: 'signal_structuring',
            temperature: 0.2,
        );

        try {
            $response = $this->gateway->complete($request);
            $structured = $this->parse($response->content ?? '');

            if (empty($structured)) {
                return $this->defaultPayload($rawText);
            }

            return $structured;
        } catch (\Throwable $e) {
            Log::warning('StructureSignalWithAiAction: structuring failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->defaultPayload($rawText);
        }
    }

    private function parse(string $content): array
    {
        $content = trim($content);

        if (str_starts_with($content, '```')) {
            $content = preg_replace('/^```\w*\n?/', '', $content);
            $content = preg_replace('/\n?```$/', '', $content);
        }

        $decoded = json_decode(trim($content), true);

        if (! is_array($decoded) || empty($decoded['title'])) {
            return [];
        }

        return [
            'title' => mb_substr((string) ($decoded['title'] ?? ''), 0, 80),
            'description' => (string) ($decoded['description'] ?? ''),
            'priority' => in_array($decoded['priority'] ?? '', ['low', 'medium', 'high', 'critical'], true)
                ? $decoded['priority']
                : 'medium',
            'tags' => array_slice(array_filter(array_map('strval', (array) ($decoded['tags'] ?? []))), 0, 5),
            'source_type' => in_array($decoded['source_type'] ?? '', ['manual', 'bug_report', 'feature_request', 'support_ticket', 'feedback', 'alert', 'incident', 'task'], true)
                ? $decoded['source_type']
                : 'manual',
            'metadata' => is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [],
        ];
    }

    private function defaultPayload(string $rawText): array
    {
        return [
            'title' => mb_substr($rawText, 0, 80),
            'description' => $rawText,
            'priority' => 'medium',
            'tags' => [],
            'source_type' => 'manual',
            'metadata' => [],
        ];
    }

    private function resolveLlm(): array
    {
        $provider = config('llm_providers.default_provider', 'anthropic');
        $model = config('llm_providers.default_model', 'claude-haiku-4-5-20251001');

        $providerKeyMap = [
            'anthropic' => config('prism.providers.anthropic.api_key'),
            'openai' => config('prism.providers.openai.api_key'),
            'google' => config('prism.providers.google.api_key'),
        ];

        if (empty($providerKeyMap[$provider] ?? null)) {
            if (! empty($providerKeyMap['openai'])) {
                return ['provider' => 'openai', 'model' => 'gpt-4o-mini'];
            }
            if (! empty($providerKeyMap['anthropic'])) {
                return ['provider' => 'anthropic', 'model' => 'claude-haiku-4-5-20251001'];
            }
            if (! empty($providerKeyMap['google'])) {
                return ['provider' => 'google', 'model' => 'gemini-2.5-flash'];
            }
        }

        return ['provider' => $provider, 'model' => $model];
    }
}
