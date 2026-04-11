<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;

final class MetricCardArtifact extends BaseArtifact
{
    public const TYPE = 'metric_card';

    private const MAX_LABEL_CHARS = 50;

    private const MAX_UNIT_CHARS = 20;

    private const MAX_CONTEXT_CHARS = 100;

    private const ALLOWED_TRENDS = ['up', 'down', 'neutral'];

    public function __construct(
        public readonly string $label,
        public readonly float $value,
        public readonly ?string $unit,
        public readonly ?float $delta,
        public readonly ?string $trend,
        public readonly ?string $context,
        public readonly ?string $sourceTool,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public function sourceTool(): ?string
    {
        return $this->sourceTool;
    }

    public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?static
    {
        $label = StringSanitizer::clean($raw['label'] ?? null, self::MAX_LABEL_CHARS);
        if ($label === null) {
            return null;
        }

        if (! is_numeric($raw['value'] ?? null)) {
            return null;
        }
        $value = (float) $raw['value'];

        $unit = StringSanitizer::clean($raw['unit'] ?? null, self::MAX_UNIT_CHARS);

        $delta = null;
        if (isset($raw['delta']) && is_numeric($raw['delta'])) {
            $delta = (float) $raw['delta'];
        }

        $trend = is_string($raw['trend'] ?? null) ? strtolower($raw['trend']) : null;
        if ($trend !== null && ! in_array($trend, self::ALLOWED_TRENDS, true)) {
            $trend = null;
        }

        $context = StringSanitizer::clean($raw['context'] ?? null, self::MAX_CONTEXT_CHARS);

        // Optional source_tool binding: if the LLM provides one, it MUST have run.
        // If it doesn't, that's OK — metric_card allows literal values (e.g. "20% of 500 = 100").
        $sourceTool = StringSanitizer::clean($raw['source_tool'] ?? null, 64);
        if ($sourceTool !== null && ! self::toolRanInTurn($sourceTool, $toolCallsInTurn)) {
            return null;
        }

        return new self(
            label: $label,
            value: $value,
            unit: $unit,
            delta: $delta,
            trend: $trend,
            context: $context,
            sourceTool: $sourceTool,
        );
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'delta' => $this->delta,
            'trend' => $this->trend,
            'context' => $this->context,
            'source_tool' => $this->sourceTool,
        ];
    }
}
