<?php

namespace App\Domain\Project\Services;

use App\Domain\Project\DTOs\ScheduleParseResultDTO;
use App\Domain\Project\Enums\OverlapPolicy;
use App\Domain\Project\Enums\ScheduleFrequency;
use App\Infrastructure\AI\Contracts\AiGatewayInterface;
use App\Infrastructure\AI\DTOs\AiRequestDTO;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Log;

class NaturalLanguageScheduleParser
{
    public function __construct(
        private readonly AiGatewayInterface $gateway,
        private readonly ProviderResolver $providerResolver,
    ) {}

    /**
     * Parse a natural language schedule description into a structured DTO.
     * Falls back to daily at 09:00 UTC if parsing fails.
     */
    public function parse(string $input): ScheduleParseResultDTO
    {
        $validFrequencies = implode(', ', array_column(ScheduleFrequency::cases(), 'value'));

        $systemPrompt = <<<PROMPT
You are a schedule parser. Convert natural language schedule descriptions into structured JSON.

Valid frequency values: {$validFrequencies}

Rules:
- Use "cron" frequency when the description specifies days of week + time (e.g. "every Monday at 9am").
- Use "cron" frequency for custom intervals like "every 2 hours", "twice daily".
- Use "every_5_minutes", "every_10_minutes", "every_15_minutes", "every_30_minutes", "hourly", "daily", "weekly", "monthly" when the description matches directly.
- For "daily at X time" use "cron" with the appropriate cron expression.
- Set cron_expression to null when frequency is not "cron".
- timezone defaults to "UTC" unless explicitly stated.
- human_readable must be a clear English sentence (e.g. "Every Monday at 9:00 AM UTC").
- overlap_policy: use "skip" (default), "queue", or "allow".

Output ONLY valid JSON, no markdown:
{
  "frequency": "cron",
  "cron_expression": "0 9 * * 1",
  "timezone": "UTC",
  "human_readable": "Every Monday at 9:00 AM UTC",
  "overlap_policy": "skip"
}
PROMPT;

        try {
            $resolved = $this->providerResolver->resolve();

            $response = $this->gateway->complete(new AiRequestDTO(
                provider: $resolved['provider'],
                model: $resolved['model'],
                systemPrompt: $systemPrompt,
                userPrompt: "Parse this schedule: {$input}",
                maxTokens: 256,
                temperature: 0.0,
                purpose: 'schedule_parsing',
            ));

            $parsed = $this->parseJson($response->content);

            if ($parsed) {
                return $this->buildDto($parsed);
            }
        } catch (\Throwable $e) {
            Log::warning('NaturalLanguageScheduleParser: LLM call failed, using daily fallback', [
                'input' => $input,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->fallback($input);
    }

    private function parseJson(string $text): ?array
    {
        $text = trim($text);

        if (str_starts_with($text, '```')) {
            $text = preg_replace('/^```(?:json)?\s*\n?/', '', $text);
            $text = preg_replace('/\n?```\s*$/', '', $text);
        }

        $decoded = json_decode(trim($text), true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function buildDto(array $parsed): ScheduleParseResultDTO
    {
        $frequencyValue = $parsed['frequency'] ?? ScheduleFrequency::Daily->value;
        $frequency = ScheduleFrequency::tryFrom($frequencyValue) ?? ScheduleFrequency::Daily;

        $overlapPolicy = OverlapPolicy::tryFrom($parsed['overlap_policy'] ?? 'skip') ?? OverlapPolicy::Skip;

        return new ScheduleParseResultDTO(
            frequency: $frequency,
            cronExpression: ($frequency === ScheduleFrequency::Cron) ? ($parsed['cron_expression'] ?? null) : null,
            timezone: $parsed['timezone'] ?? 'UTC',
            humanReadable: $parsed['human_readable'] ?? 'Daily at 9:00 AM UTC',
            overlapPolicy: $overlapPolicy,
        );
    }

    private function fallback(string $input): ScheduleParseResultDTO
    {
        return new ScheduleParseResultDTO(
            frequency: ScheduleFrequency::Daily,
            cronExpression: null,
            timezone: 'UTC',
            humanReadable: "Daily at 9:00 AM UTC (parsed from: \"{$input}\")",
            overlapPolicy: OverlapPolicy::Skip,
        );
    }
}
