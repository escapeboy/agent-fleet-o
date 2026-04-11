<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;

final class ChartArtifact extends BaseArtifact
{
    public const TYPE = 'chart';

    private const MAX_POINTS = 100;

    private const MAX_TITLE_CHARS = 100;

    private const ALLOWED_CHART_TYPES = ['line', 'bar', 'pie', 'area'];

    /**
     * @param  list<array{label: string, value: float}>  $dataPoints
     */
    public function __construct(
        public readonly string $title,
        public readonly string $chartType,
        public readonly string $xAxisLabel,
        public readonly string $yAxisLabel,
        public readonly array $dataPoints,
        public readonly string $sourceTool,
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
        $sourceTool = StringSanitizer::clean($raw['source_tool'] ?? null, 64);
        if ($sourceTool === null || ! self::toolRanInTurn($sourceTool, $toolCallsInTurn)) {
            return null;
        }

        $chartType = is_string($raw['chart_type'] ?? null) ? strtolower($raw['chart_type']) : null;
        if (! in_array($chartType, self::ALLOWED_CHART_TYPES, true)) {
            return null;
        }

        $title = StringSanitizer::cleanOrEmpty($raw['title'] ?? '', self::MAX_TITLE_CHARS);
        $xLabel = StringSanitizer::cleanOrEmpty($raw['x_axis_label'] ?? '', 40);
        $yLabel = StringSanitizer::cleanOrEmpty($raw['y_axis_label'] ?? '', 40);

        $points = [];
        $rawPoints = $raw['data_points'] ?? [];
        if (! is_array($rawPoints)) {
            return null;
        }

        foreach (array_slice($rawPoints, 0, self::MAX_POINTS) as $pt) {
            if (! is_array($pt)) {
                continue;
            }
            $label = StringSanitizer::clean($pt['label'] ?? null, 40);
            $value = is_numeric($pt['value'] ?? null) ? (float) $pt['value'] : null;
            if ($label === null || $value === null) {
                continue;
            }
            $points[] = ['label' => $label, 'value' => $value];
        }

        if ($points === []) {
            return null;
        }

        return new self(
            title: $title,
            chartType: $chartType,
            xAxisLabel: $xLabel,
            yAxisLabel: $yLabel,
            dataPoints: $points,
            sourceTool: $sourceTool,
        );
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'title' => $this->title,
            'chart_type' => $this->chartType,
            'x_axis_label' => $this->xAxisLabel,
            'y_axis_label' => $this->yAxisLabel,
            'data_points' => $this->dataPoints,
            'source_tool' => $this->sourceTool,
        ];
    }
}
