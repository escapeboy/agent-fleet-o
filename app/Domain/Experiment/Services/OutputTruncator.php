<?php

namespace App\Domain\Experiment\Services;

class OutputTruncator
{
    private const DEFAULT_MAX_BYTES = 32768; // 32KB for display

    private const CONTEXT_MAX_BYTES = 8192; // 8KB for inter-stage passing

    private const HARD_CAP_BYTES = 10 * 1024 * 1024; // 10MB absolute max before line-splitting

    private const HEAD_RATIO = 0.6;

    private const TAIL_RATIO = 0.4;

    /**
     * Truncate output using a 60/40 head-tail split, preserving a transparent marker.
     * Returns the output unchanged when it fits within the limit.
     */
    public function truncate(string $output, int $maxBytes = self::DEFAULT_MAX_BYTES): string
    {
        if (strlen($output) <= $maxBytes) {
            return $output;
        }

        // Hard cap before line-splitting prevents memory exhaustion on single-line mega-inputs.
        if (strlen($output) > self::HARD_CAP_BYTES) {
            $output = substr($output, 0, self::HARD_CAP_BYTES);
        }

        $lines = explode("\n", $output);
        $totalLines = count($lines);

        $headBudget = (int) ($maxBytes * self::HEAD_RATIO);
        $tailBudget = (int) ($maxBytes * self::TAIL_RATIO);

        // Collect head lines
        $headLines = [];
        $headBytes = 0;
        foreach ($lines as $line) {
            $lineBytes = strlen($line) + 1; // +1 for the newline
            if ($headBytes + $lineBytes > $headBudget) {
                break;
            }
            $headLines[] = $line;
            $headBytes += $lineBytes;
        }

        // Collect tail lines (from the end)
        $tailLines = [];
        $tailBytes = 0;
        for ($i = $totalLines - 1; $i >= count($headLines); $i--) {
            $lineBytes = strlen($lines[$i]) + 1;
            if ($tailBytes + $lineBytes > $tailBudget) {
                break;
            }
            array_unshift($tailLines, $lines[$i]);
            $tailBytes += $lineBytes;
        }

        $skippedLines = $totalLines - count($headLines) - count($tailLines);
        $skippedKb = round((strlen($output) - $headBytes - $tailBytes) / 1024, 1);

        $marker = sprintf(
            "\n[... %d lines / %sKB truncated — showing first %d + last %d lines ...]\n",
            $skippedLines,
            $skippedKb,
            count($headLines),
            count($tailLines),
        );

        return implode("\n", $headLines).$marker.implode("\n", $tailLines);
    }

    /**
     * Tighter truncation limit for passing output between pipeline stages.
     */
    public function truncateForContext(string $output, int $maxBytes = self::CONTEXT_MAX_BYTES): string
    {
        return $this->truncate($output, $maxBytes);
    }
}
