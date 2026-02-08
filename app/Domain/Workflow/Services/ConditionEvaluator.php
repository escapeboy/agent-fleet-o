<?php

namespace App\Domain\Workflow\Services;

use Illuminate\Support\Arr;

class ConditionEvaluator
{
    /**
     * Evaluate a condition against a context of node outputs.
     *
     * Condition format (simple):
     *   {"field": "output.score", "operator": ">", "value": 0.7}
     *
     * Compound (AND):
     *   {"all": [{"field": "...", "operator": "...", "value": ...}, ...]}
     *
     * Compound (OR):
     *   {"any": [{"field": "...", "operator": "...", "value": ...}, ...]}
     *
     * @param  array|null  $condition  The condition definition
     * @param  array  $context  Map of node_id => output data
     * @param  string|null  $predecessorNodeId  The node whose output to evaluate against (for simple field references)
     */
    public function evaluate(?array $condition, array $context, ?string $predecessorNodeId = null): bool
    {
        if (empty($condition)) {
            return true;
        }

        // Compound AND
        if (isset($condition['all'])) {
            foreach ($condition['all'] as $subCondition) {
                if (! $this->evaluate($subCondition, $context, $predecessorNodeId)) {
                    return false;
                }
            }

            return true;
        }

        // Compound OR
        if (isset($condition['any'])) {
            foreach ($condition['any'] as $subCondition) {
                if ($this->evaluate($subCondition, $context, $predecessorNodeId)) {
                    return true;
                }
            }

            return false;
        }

        // Simple condition
        return $this->evaluateSimple($condition, $context, $predecessorNodeId);
    }

    private function evaluateSimple(array $condition, array $context, ?string $predecessorNodeId): bool
    {
        $field = $condition['field'] ?? null;
        $operator = $condition['operator'] ?? '==';
        $expected = $condition['value'] ?? null;

        if (! $field) {
            return true;
        }

        $actual = $this->resolveField($field, $context, $predecessorNodeId);

        return $this->compare($actual, $operator, $expected);
    }

    /**
     * Resolve a field value from the context.
     *
     * Supported formats:
     *   "output.score"              → predecessor node's output.score
     *   "node:{id}.output.score"    → specific node's output.score
     *   "experiment.thesis"         → experiment-level data (must be in context under '_experiment')
     */
    private function resolveField(string $field, array $context, ?string $predecessorNodeId): mixed
    {
        // Explicit node reference: "node:{uuid}.output.field"
        if (str_starts_with($field, 'node:')) {
            $parts = explode('.', $field, 2);
            $nodeId = str_replace('node:', '', $parts[0]);
            $path = $parts[1] ?? '';

            return Arr::get($context[$nodeId] ?? [], $path);
        }

        // Experiment-level reference: "experiment.field"
        if (str_starts_with($field, 'experiment.')) {
            $path = substr($field, strlen('experiment.'));

            return Arr::get($context['_experiment'] ?? [], $path);
        }

        // Default: predecessor node's output
        if ($predecessorNodeId && isset($context[$predecessorNodeId])) {
            return Arr::get($context[$predecessorNodeId], $field);
        }

        return null;
    }

    private function compare(mixed $actual, string $operator, mixed $expected): bool
    {
        // Null-safe: if actual is null, most comparisons return false
        if ($actual === null && $operator !== '==' && $operator !== '!=') {
            return false;
        }

        return match ($operator) {
            '>' => $actual > $expected,
            '<' => $actual < $expected,
            '>=' => $actual >= $expected,
            '<=' => $actual <= $expected,
            '==' => $actual == $expected,
            '!=' => $actual != $expected,
            'contains' => is_string($actual) && str_contains($actual, (string) $expected),
            'not_contains' => is_string($actual) && ! str_contains($actual, (string) $expected),
            'in' => is_array($expected) && in_array($actual, $expected),
            'not_in' => is_array($expected) && ! in_array($actual, $expected),
            'is_null' => $actual === null,
            'is_not_null' => $actual !== null,
            default => false,
        };
    }
}
