<?php

declare(strict_types=1);

namespace App\Domain\Release\Services\Diff;

/**
 * Deep recursive JSON diff. Walks both trees and emits per-path verdicts:
 *   - add:       key only in right
 *   - remove:    key only in left
 *   - change:    key in both with different values (deeply compared)
 *   - unchanged: key in both with identical values (omitted by default)
 *
 * Paths use JSONPath-style notation: $.users[0].email
 */
class JsonStructuralDiff implements DiffStrategyInterface
{
    public function diff(?string $left, ?string $right, array $context = []): array
    {
        $leftData = $this->safeDecode($left);
        $rightData = $this->safeDecode($right);

        if ($leftData === false || $rightData === false) {
            return [['type' => 'unsupported', 'path' => '$', 'left' => null, 'right' => null, 'reason' => 'invalid JSON on one or both sides']];
        }

        $segments = [];
        $this->walk('$', $leftData, $rightData, $segments);

        return $segments;
    }

    public function supports(?string $contentType): bool
    {
        return $contentType !== null
            && (str_contains($contentType, 'json')
                || str_ends_with($contentType, '+json'));
    }

    public function name(): string
    {
        return 'json';
    }

    /**
     * @param  array<int, array<string, mixed>>  $segments
     */
    private function walk(string $path, mixed $left, mixed $right, array &$segments): void
    {
        if ($left === $right) {
            return;
        }

        $isLeftAssoc = is_array($left) && $this->isAssoc($left);
        $isRightAssoc = is_array($right) && $this->isAssoc($right);
        $isLeftList = is_array($left) && ! $isLeftAssoc;
        $isRightList = is_array($right) && ! $isRightAssoc;

        if (! is_array($left) || ! is_array($right) || $isLeftAssoc !== $isRightAssoc) {
            $segments[] = [
                'type' => 'change',
                'path' => $path,
                'left' => $left,
                'right' => $right,
            ];

            return;
        }

        if ($isLeftAssoc && $isRightAssoc) {
            $allKeys = array_unique(array_merge(array_keys($left), array_keys($right)));
            foreach ($allKeys as $key) {
                $childPath = $path.'.'.$key;
                $hasLeft = array_key_exists($key, $left);
                $hasRight = array_key_exists($key, $right);

                if ($hasLeft && ! $hasRight) {
                    $segments[] = ['type' => 'remove', 'path' => $childPath, 'left' => $left[$key], 'right' => null];
                } elseif (! $hasLeft && $hasRight) {
                    $segments[] = ['type' => 'add', 'path' => $childPath, 'left' => null, 'right' => $right[$key]];
                } else {
                    $this->walk($childPath, $left[$key], $right[$key], $segments);
                }
            }

            return;
        }

        if ($isLeftList && $isRightList) {
            $maxLen = max(count($left), count($right));
            for ($i = 0; $i < $maxLen; $i++) {
                $childPath = $path.'['.$i.']';
                $hasLeft = array_key_exists($i, $left);
                $hasRight = array_key_exists($i, $right);

                if ($hasLeft && ! $hasRight) {
                    $segments[] = ['type' => 'remove', 'path' => $childPath, 'left' => $left[$i], 'right' => null];
                } elseif (! $hasLeft && $hasRight) {
                    $segments[] = ['type' => 'add', 'path' => $childPath, 'left' => null, 'right' => $right[$i]];
                } else {
                    $this->walk($childPath, $left[$i], $right[$i], $segments);
                }
            }
        }
    }

    private function isAssoc(array $arr): bool
    {
        if ($arr === []) {
            return false;
        }

        return array_keys($arr) !== range(0, count($arr) - 1);
    }

    private function safeDecode(?string $content): mixed
    {
        if ($content === null || trim($content) === '') {
            return null;
        }

        try {
            return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return false;
        }
    }
}
