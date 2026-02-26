<?php

namespace App\Domain\Trigger\Services;

use App\Domain\Signal\Models\Signal;

/**
 * Evaluates a TriggerRule's conditions against a Signal's payload.
 * Uses whitelist-only operators — no dynamic code execution.
 */
class TriggerConditionEvaluator
{
    /** @var list<string> */
    private const ALLOWED_OPERATORS = ['eq', 'neq', 'gte', 'lte', 'contains', 'not_contains', 'exists'];

    /** @var string Pattern for valid field path keys (alphanumeric + dots + underscores) */
    private const FIELD_PATH_PATTERN = '/^[a-zA-Z0-9_.]+$/';

    /**
     * @param  array<string, mixed>|null  $conditions
     */
    public function evaluate(?array $conditions, Signal $signal): bool
    {
        if (empty($conditions)) {
            return true; // No conditions = always matches
        }

        foreach ($conditions as $fieldPath => $constraint) {
            if (! $this->isValidFieldPath($fieldPath)) {
                return false;
            }

            $value = $this->resolvePath($fieldPath, $signal->payload ?? []);

            if (! $this->evaluateConstraint($value, $constraint)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Validate a conditions array for use on save.
     * Returns a list of validation errors (empty = valid).
     *
     * @param  array<string, mixed>  $conditions
     * @return list<string>
     */
    public function validate(array $conditions): array
    {
        $errors = [];

        foreach ($conditions as $fieldPath => $constraint) {
            if (! $this->isValidFieldPath($fieldPath)) {
                $errors[] = "Invalid field path: '{$fieldPath}'. Only alphanumeric characters, dots, and underscores are allowed.";

                continue;
            }

            if (! is_array($constraint)) {
                $errors[] = "Constraint for '{$fieldPath}' must be an object with an operator key.";

                continue;
            }

            foreach (array_keys($constraint) as $operator) {
                if (! in_array($operator, self::ALLOWED_OPERATORS, true)) {
                    $errors[] = "Unknown operator '{$operator}' for field '{$fieldPath}'. Allowed: ".implode(', ', self::ALLOWED_OPERATORS).'.';
                }
            }
        }

        return $errors;
    }

    private function isValidFieldPath(string $path): bool
    {
        return (bool) preg_match(self::FIELD_PATH_PATTERN, $path);
    }

    /**
     * Resolve a dot-notation path in a nested array.
     */
    private function resolvePath(string $path, array $data): mixed
    {
        $parts = explode('.', $path);
        $current = $data;

        foreach ($parts as $part) {
            if (! is_array($current) || ! array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $constraint
     */
    private function evaluateConstraint(mixed $value, array $constraint): bool
    {
        foreach ($constraint as $operator => $expected) {
            $result = match ($operator) {
                'eq' => $value === $expected,
                'neq' => $value !== $expected,
                'gte' => $value !== null && $value >= $expected,
                'lte' => $value !== null && $value <= $expected,
                'contains' => is_string($value) && str_contains(strtolower($value), strtolower((string) $expected)),
                'not_contains' => ! is_string($value) || ! str_contains(strtolower($value), strtolower((string) $expected)),
                'exists' => $expected ? $value !== null : $value === null,
                default => false, // Unknown operator — fail closed
            };

            if (! $result) {
                return false;
            }
        }

        return true;
    }
}
