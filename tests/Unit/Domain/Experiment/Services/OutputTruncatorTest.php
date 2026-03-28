<?php

namespace Tests\Unit\Domain\Experiment\Services;

use App\Domain\Experiment\Services\OutputTruncator;
use PHPUnit\Framework\TestCase;

class OutputTruncatorTest extends TestCase
{
    private OutputTruncator $truncator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->truncator = new OutputTruncator;
    }

    public function test_output_under_limit_is_returned_unchanged(): void
    {
        $output = str_repeat("line\n", 10);

        $result = $this->truncator->truncate($output, 10_000);

        $this->assertSame($output, $result);
    }

    public function test_output_over_limit_contains_truncation_marker(): void
    {
        $lines = array_map(fn ($i) => "line number $i with some content to pad the length", range(1, 500));
        $output = implode("\n", $lines);

        $result = $this->truncator->truncate($output, 2048);

        $this->assertStringContainsString('[... ', $result);
        $this->assertStringContainsString('truncated', $result);
        $this->assertStringContainsString('showing first', $result);
    }

    public function test_tail_lines_are_preserved_after_marker(): void
    {
        $lines = array_map(fn ($i) => "line $i", range(1, 500));
        $output = implode("\n", $lines);
        $lastLine = 'line 500';

        $result = $this->truncator->truncate($output, 2048);

        $this->assertStringContainsString($lastLine, $result);
        // Last line should appear after the marker
        $markerPos = strpos($result, '[...');
        $lastLinePos = strrpos($result, $lastLine);
        $this->assertGreaterThan($markerPos, $lastLinePos);
    }

    public function test_marker_shows_correct_line_counts(): void
    {
        $lines = array_map(fn ($i) => str_repeat('x', 100), range(1, 200));
        $output = implode("\n", $lines);

        $result = $this->truncator->truncate($output, 4096);

        // Should show "showing first N + last M lines"
        $this->assertMatchesRegularExpression('/showing first \d+ \+ last \d+ lines/', $result);
    }

    public function test_truncate_for_context_uses_tighter_limit(): void
    {
        $bigOutput = str_repeat("line of content\n", 1000);

        $displayResult = $this->truncator->truncate($bigOutput);
        $contextResult = $this->truncator->truncateForContext($bigOutput);

        $this->assertLessThanOrEqual(strlen($displayResult), strlen($contextResult) + 100);
        $this->assertLessThanOrEqual(8192 + 200, strlen($contextResult)); // within context limit + marker overhead
    }

    public function test_exactly_at_limit_is_not_truncated(): void
    {
        $output = str_repeat('a', 100);

        $result = $this->truncator->truncate($output, 100);

        $this->assertSame($output, $result);
    }
}
