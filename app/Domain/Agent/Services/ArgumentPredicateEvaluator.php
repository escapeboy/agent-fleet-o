<?php

namespace App\Domain\Agent\Services;

/**
 * Pure evaluator for input-conditioned tool-call predicates (eve borrow).
 *
 * Given a list of predicate definitions and a tool call's named arguments,
 * returns the first matching predicate's outcome, or null when none match.
 * A predicate inspects ONE named argument (optionally after a whitelisted
 * transform), compares it with an operator, and declares an action.
 *
 * Predicate shape:
 *   ['arg' => 'sql', 'op' => 'gt', 'value' => 50, 'transform' => 'length',
 *    'action' => 'require_approval', 'reason' => 'Large query']
 *
 * Deterministic and side-effect-free — the governor decides what to DO with a
 * match (block / raise an approval). Unknown transforms and invalid regexes
 * are treated as non-matching rather than throwing (fail-open here; the
 * governor stays fail-closed on anything it cannot evaluate).
 */
class ArgumentPredicateEvaluator
{
    /**
     * Predicates originate from user-supplied JSON hook config, so each element
     * is treated as untrusted (mixed) and validated at runtime.
     *
     * @param  array<int, mixed>  $predicates
     * @param  array<string, mixed>  $args
     * @return array{action: string, reason: string, arg: string, op: string}|null
     */
    public function evaluate(array $predicates, array $args): ?array
    {
        foreach ($predicates as $predicate) {
            if (! is_array($predicate)) {
                continue;
            }

            $arg = $predicate['arg'] ?? null;
            $op = $predicate['op'] ?? null;
            if (! is_string($arg) || ! is_string($op) || ! array_key_exists($arg, $args)) {
                continue;
            }

            $value = $this->applyTransform($args[$arg], $predicate['transform'] ?? null);

            if (! $this->matches($value, $op, $predicate['value'] ?? null)) {
                continue;
            }

            $action = $predicate['action'] ?? 'block';
            if (! in_array($action, ['block', 'require_approval'], true)) {
                $action = 'block';
            }

            $reason = $predicate['reason'] ?? null;

            return [
                'action' => $action,
                'reason' => is_string($reason) && $reason !== ''
                    ? $reason
                    : "Argument '{$arg}' matched predicate '{$op}'.",
                'arg' => $arg,
                'op' => $op,
            ];
        }

        return null;
    }

    private function matches(mixed $value, string $op, mixed $threshold): bool
    {
        return match ($op) {
            'gt' => $this->numericCompare($value, $threshold, fn ($a, $b) => $a > $b),
            'gte' => $this->numericCompare($value, $threshold, fn ($a, $b) => $a >= $b),
            'lt' => $this->numericCompare($value, $threshold, fn ($a, $b) => $a < $b),
            'lte' => $this->numericCompare($value, $threshold, fn ($a, $b) => $a <= $b),
            'eq' => $this->looseEquals($value, $threshold),
            'neq' => ! $this->looseEquals($value, $threshold),
            'contains' => is_string($threshold) && str_contains($this->stringify($value), $threshold),
            'matches' => is_string($threshold) && $this->regexMatches($threshold, $this->stringify($value)),
            default => false,
        };
    }

    private function applyTransform(mixed $raw, mixed $transform): mixed
    {
        if (! is_string($transform) || $transform === '') {
            return $raw;
        }

        return match ($transform) {
            'length' => is_array($raw) ? count($raw) : mb_strlen($this->stringify($raw)),
            'lower' => mb_strtolower($this->stringify($raw)),
            'upper' => mb_strtoupper($this->stringify($raw)),
            'abs' => is_numeric($raw) ? abs((float) $raw) : $raw,
            'int' => is_numeric($raw) ? (int) $raw : $raw,
            'float' => is_numeric($raw) ? (float) $raw : $raw,
            default => $raw, // unknown transform → identity (non-matching at worst)
        };
    }

    /**
     * @param  callable(float, float): bool  $cmp
     */
    private function numericCompare(mixed $a, mixed $b, callable $cmp): bool
    {
        if (! is_numeric($a) || ! is_numeric($b)) {
            return false;
        }

        return $cmp((float) $a, (float) $b);
    }

    private function looseEquals(mixed $a, mixed $b): bool
    {
        if (is_numeric($a) && is_numeric($b)) {
            return (float) $a === (float) $b;
        }

        return $this->stringify($a) === $this->stringify($b);
    }

    private function regexMatches(string $pattern, string $subject): bool
    {
        $result = @preg_match($pattern, $subject);

        return $result === 1;
    }

    private function stringify(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }
}
