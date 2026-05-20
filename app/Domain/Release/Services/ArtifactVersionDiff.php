<?php

declare(strict_types=1);

namespace App\Domain\Release\Services;

/**
 * Plain-text artifact diff using PHP's built-in xdiff_string_diff if available,
 * else a unified-diff fallback that diffs line-by-line.
 *
 * Diff returns array of segments:
 *   ['type' => 'context'|'add'|'remove'|'header', 'left' => ?int, 'right' => ?int, 'text' => string]
 *
 * MVP: text-only. Binary content (images, PDFs) returns a single 'unsupported' segment.
 */
class ArtifactVersionDiff
{
    /**
     * @return array<int, array{type: string, left: ?int, right: ?int, text: string}>
     */
    public function diff(?string $left, ?string $right): array
    {
        $left ??= '';
        $right ??= '';

        if ($this->isLikelyBinary($left) || $this->isLikelyBinary($right)) {
            return [['type' => 'unsupported', 'left' => null, 'right' => null, 'text' => '(binary content — diff unsupported)']];
        }

        $leftLines = $left === '' ? [] : preg_split("/\r\n|\r|\n/", $left);
        $rightLines = $right === '' ? [] : preg_split("/\r\n|\r|\n/", $right);

        return $this->myersDiff($leftLines, $rightLines);
    }

    /**
     * @param  array<int, string>  $a
     * @param  array<int, string>  $b
     * @return array<int, array{type: string, left: ?int, right: ?int, text: string}>
     */
    private function myersDiff(array $a, array $b): array
    {
        // Compute LCS lengths with O(NM) DP — sufficient for the tens-to-hundreds-of-lines
        // typical MVP case. Larger artifacts get truncated.
        $maxLines = 2000;
        if (count($a) > $maxLines || count($b) > $maxLines) {
            return [['type' => 'unsupported', 'left' => null, 'right' => null, 'text' => sprintf('(too large to diff — %d/%d lines, MVP cap %d)', count($a), count($b), $maxLines)]];
        }

        $m = count($a);
        $n = count($b);
        $lcs = array_fill(0, $m + 1, array_fill(0, $n + 1, 0));

        for ($i = 1; $i <= $m; $i++) {
            for ($j = 1; $j <= $n; $j++) {
                $lcs[$i][$j] = ($a[$i - 1] === $b[$j - 1])
                    ? $lcs[$i - 1][$j - 1] + 1
                    : max($lcs[$i - 1][$j], $lcs[$i][$j - 1]);
            }
        }

        // Backtrack
        $segments = [];
        $i = $m;
        $j = $n;
        while ($i > 0 || $j > 0) {
            if ($i > 0 && $j > 0 && $a[$i - 1] === $b[$j - 1]) {
                array_unshift($segments, ['type' => 'context', 'left' => $i, 'right' => $j, 'text' => $a[$i - 1]]);
                $i--;
                $j--;
            } elseif ($j > 0 && ($i === 0 || $lcs[$i][$j - 1] >= $lcs[$i - 1][$j])) {
                array_unshift($segments, ['type' => 'add', 'left' => null, 'right' => $j, 'text' => $b[$j - 1]]);
                $j--;
            } else {
                array_unshift($segments, ['type' => 'remove', 'left' => $i, 'right' => null, 'text' => $a[$i - 1]]);
                $i--;
            }
        }

        return $segments;
    }

    private function isLikelyBinary(string $content): bool
    {
        if ($content === '') {
            return false;
        }

        // Sample the first 1024 bytes; if any null byte is present, treat as binary.
        $sample = substr($content, 0, 1024);

        return str_contains($sample, "\0");
    }
}
