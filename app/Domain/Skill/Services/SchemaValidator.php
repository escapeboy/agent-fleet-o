<?php

namespace App\Domain\Skill\Services;

class SchemaValidator
{
    /**
     * Validate data against a JSON Schema-like definition.
     *
     * Schema format (simplified JSON Schema subset):
     * {
     *   "type": "object",
     *   "properties": {
     *     "field": { "type": "string", "required": true },
     *     "count": { "type": "integer", "required": false }
     *   }
     * }
     *
     * @param  array  $data  The data to validate
     * @param  array  $schema  The schema definition
     * @return array{valid: bool, errors: list<string>}
     */
    public function validate(array $data, array $schema): array
    {
        $errors = [];

        if (empty($schema) || ! isset($schema['properties'])) {
            return ['valid' => true, 'errors' => []];
        }

        $properties = $schema['properties'] ?? [];
        $requiredFields = $schema['required'] ?? [];

        // Also support per-property "required" flag
        foreach ($properties as $field => $definition) {
            if (($definition['required'] ?? false) && ! in_array($field, $requiredFields)) {
                $requiredFields[] = $field;
            }
        }

        // Check required fields
        foreach ($requiredFields as $field) {
            if (! array_key_exists($field, $data)) {
                $errors[] = "Missing required field: {$field}";
            }
        }

        // Validate types for present fields
        foreach ($data as $field => $value) {
            if (! isset($properties[$field])) {
                continue; // Extra fields are allowed
            }

            $expectedType = $properties[$field]['type'] ?? null;

            if ($expectedType && ! $this->checkType($value, $expectedType)) {
                $errors[] = "Field '{$field}' expected type '{$expectedType}', got '".gettype($value)."'";
            }

            // Validate enum constraints
            if (isset($properties[$field]['enum']) && ! in_array($value, $properties[$field]['enum'], true)) {
                $allowed = implode(', ', $properties[$field]['enum']);
                $errors[] = "Field '{$field}' must be one of: {$allowed}";
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    private function checkType(mixed $value, string $expectedType): bool
    {
        return match ($expectedType) {
            'string' => is_string($value),
            'integer', 'int' => is_int($value),
            'number', 'float' => is_numeric($value),
            'boolean', 'bool' => is_bool($value),
            'array' => is_array($value) && array_is_list($value),
            'object' => is_array($value) && ! array_is_list($value),
            'null' => is_null($value),
            default => true,
        };
    }
}
