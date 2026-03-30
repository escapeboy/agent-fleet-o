<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Exceptions\MetricExtractionException;
use App\Domain\Skill\Models\SkillExecution;

/**
 * Extracts a numeric metric value from a SkillExecution result.
 *
 * Supported metric_name values:
 *  - latency_ms          — execution duration in milliseconds (built-in)
 *  - output_length       — token count of the output (whitespace-split, built-in)
 *  - json:<json_path>    — extracts a numeric value from JSON output using dot-notation path
 *  - regex:<pattern>     — extracts the first capture group matching a numeric value
 */
class MeasureSkillMetricAction
{
    /**
     * @param  SkillExecution  $execution  The completed execution to measure
     * @param  string  $metricName  The metric to extract
     * @return float The extracted metric value
     *
     * @throws MetricExtractionException
     */
    public function execute(SkillExecution $execution, string $metricName): float
    {
        return match (true) {
            $metricName === 'latency_ms' => $this->extractLatency($execution),
            $metricName === 'output_length' => $this->extractOutputLength($execution),
            str_starts_with($metricName, 'json:') => $this->extractJsonPath($execution, substr($metricName, 5)),
            str_starts_with($metricName, 'regex:') => $this->extractRegex($execution, substr($metricName, 6)),
            default => throw new MetricExtractionException("Unknown metric: {$metricName}"),
        };
    }

    private function extractLatency(SkillExecution $execution): float
    {
        if ($execution->duration_ms === null) {
            throw new MetricExtractionException('Execution has no duration_ms recorded.');
        }

        return (float) $execution->duration_ms;
    }

    private function extractOutputLength(SkillExecution $execution): float
    {
        $output = $this->rawOutput($execution);

        return (float) count(preg_split('/\s+/', trim($output), -1, PREG_SPLIT_NO_EMPTY));
    }

    private function extractJsonPath(SkillExecution $execution, string $path): float
    {
        $output = $this->rawOutput($execution);
        $decoded = json_decode($output, true);

        if (! is_array($decoded)) {
            throw new MetricExtractionException("Output is not valid JSON for json:{$path}.");
        }

        $segments = explode('.', $path);
        $current = $decoded;

        foreach ($segments as $segment) {
            if (! is_array($current) || ! array_key_exists($segment, $current)) {
                throw new MetricExtractionException("JSON path '{$path}' not found in output.");
            }
            $current = $current[$segment];
        }

        if (! is_numeric($current)) {
            throw new MetricExtractionException("JSON path '{$path}' did not resolve to a numeric value.");
        }

        return (float) $current;
    }

    private function extractRegex(SkillExecution $execution, string $pattern): float
    {
        $output = $this->rawOutput($execution);

        if (@preg_match($pattern, $output, $matches) !== 1) {
            throw new MetricExtractionException("Regex '{$pattern}' did not match the output or is invalid.");
        }

        $value = $matches[1] ?? $matches[0];

        if (! is_numeric($value)) {
            throw new MetricExtractionException("Regex '{$pattern}' matched '{$value}' which is not numeric.");
        }

        return (float) $value;
    }

    private function rawOutput(SkillExecution $execution): string
    {
        $output = $execution->output;

        if (is_array($output)) {
            return json_encode($output) ?: '';
        }

        return (string) ($output ?? '');
    }
}
