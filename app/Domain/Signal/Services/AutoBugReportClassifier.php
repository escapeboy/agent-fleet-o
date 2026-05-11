<?php

declare(strict_types=1);

namespace App\Domain\Signal\Services;

use App\Domain\Signal\Models\Signal;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Support\LlmDefaults;
use Illuminate\Support\Facades\Log;

class AutoBugReportClassifier
{
    public function __construct(private readonly AiGatewayInterface $gateway) {}

    /**
     * Classify a bug-report Signal as 'bug' or 'feature_request'.
     *
     * @return array{classified_type: string, confidence: float, rationale: string}
     *
     * @throws \RuntimeException on malformed or out-of-enum LLM output
     */
    public function classify(Signal $signal): array
    {
        $payload = $signal->payload ?? [];
        $title = (string) ($payload['title'] ?? '');
        $description = (string) ($payload['description'] ?? '');
        $severity = (string) ($payload['severity'] ?? 'major');
        $url = (string) ($payload['url'] ?? '');

        $request = new AiRequestDTO(
            provider: LlmDefaults::provider(),
            model: LlmDefaults::model(),
            systemPrompt: $this->systemPrompt(),
            userPrompt: $this->buildUserPrompt($title, $description, $severity, $url),
            maxTokens: 400,
            teamId: $signal->team_id,
            purpose: 'bug_triage',
            temperature: 0.0,
        );

        $response = $this->gateway->complete($request);

        return $this->parseStructuredJson($response->content ?? '', $signal->id);
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
Ти си класификатор на доклади от потребители за софтуерен продукт.

Получаваш доклад на български език. Връщаш СТРОГО JSON със следния формат:

{
  "classified_type": "bug" | "feature_request",
  "confidence": 0.0 до 1.0,
  "rationale_bg": "Кратко обяснение защо (1-2 изречения)"
}

Правила:
- "bug" = нещо което работи грешно, дава грешка, или не отговаря на документацията / очакванията.
- "feature_request" = нещо което потребителят иска да бъде различно/добавено/премахнато, но не е счупено.
- Гранични случаи (UX подобрения, "по-добре да...", "би било хубаво...") → feature_request.
- "грешен формат на дата", "числото се показва с грешен знак" → bug, не feature_request.
- Никакъв текст извън JSON. Никакви markdown ограждания.
PROMPT;
    }

    private function buildUserPrompt(string $title, string $description, string $severity, string $url): string
    {
        return "Заглавие: {$title}\n\n"
            ."Описание:\n{$description}\n\n"
            ."Сериозност (избрана от потребителя): {$severity}\n"
            ."URL: {$url}";
    }

    /**
     * @return array{classified_type: string, confidence: float, rationale: string}
     */
    private function parseStructuredJson(string $raw, string $signalId): array
    {
        $trimmed = trim($raw);
        $trimmed = (string) preg_replace('/^```(?:json)?\s*|\s*```$/m', '', $trimmed);

        $decoded = json_decode(trim($trimmed), true);

        if (! is_array($decoded) || ! isset($decoded['classified_type'])) {
            Log::warning('AutoBugReportClassifier: malformed LLM output', [
                'signal_id' => $signalId,
                'raw' => mb_substr($raw, 0, 500),
            ]);
            throw new \RuntimeException('Classifier returned malformed JSON');
        }

        $type = $decoded['classified_type'];
        if (! in_array($type, ['bug', 'feature_request'], true)) {
            throw new \RuntimeException("Unknown classified_type: {$type}");
        }

        return [
            'classified_type' => $type,
            'confidence' => max(0.0, min(1.0, (float) ($decoded['confidence'] ?? 0.5))),
            'rationale' => (string) ($decoded['rationale_bg'] ?? ''),
        ];
    }
}
