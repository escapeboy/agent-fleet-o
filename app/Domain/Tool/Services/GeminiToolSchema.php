<?php

namespace App\Domain\Tool\Services;

use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool as PrismToolObject;

/**
 * Makes RawSchema tool parameters acceptable to Gemini.
 *
 * Prism's Gemini SchemaMap passes a RawSchema through untouched, and Gemini's
 * function declarations take an OpenAPI subset where `type` must be a single
 * string. JSON Schema unions such as `["string", "null"]` (every nullable
 * laravel/mcp property bridged via LaravelMcpTool, plus ToolTranslator's
 * browser `max_steps`) make Gemini reject the whole request with
 * "Proto field is not repeating, cannot start list" (Sentry fleetq #1110).
 *
 * A union becomes its first non-null type, with `nullable: true` when null was
 * part of it. Typed Prism schemas are already mapped by SchemaMap and are left
 * alone. Changed tools are clones; the input objects are never mutated.
 */
final class GeminiToolSchema
{
    /**
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $tools
     * @return array<TKey, mixed>
     */
    public static function apply(array $tools): array
    {
        foreach ($tools as $key => $tool) {
            if (! $tool instanceof PrismToolObject) {
                continue;
            }

            $fixed = null;
            foreach ($tool->parameters() as $name => $parameter) {
                if (! $parameter instanceof RawSchema) {
                    continue;
                }

                $normalized = self::normalize($parameter->schema);
                if ($normalized === $parameter->schema) {
                    continue;
                }

                $fixed ??= clone $tool;
                // Keyed by name, so this replaces the parameter; required list is untouched.
                $fixed->withParameter(new RawSchema((string) $name, $normalized), required: false);
            }

            if ($fixed !== null) {
                $tools[$key] = $fixed;
            }
        }

        return $tools;
    }

    /**
     * @param  array<string, mixed>  $schema
     * @return array<string, mixed>
     */
    public static function normalize(array $schema): array
    {
        if (isset($schema['type']) && is_array($schema['type'])) {
            $types = array_values(array_filter($schema['type'], fn ($t): bool => $t !== 'null'));
            if (count($types) < count($schema['type'])) {
                $schema['nullable'] = true;
            }
            $schema['type'] = $types[0] ?? 'string';
        }

        if (isset($schema['properties']) && is_array($schema['properties'])) {
            foreach ($schema['properties'] as $name => $property) {
                if (is_array($property)) {
                    $schema['properties'][$name] = self::normalize($property);
                }
            }
        }

        if (isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::normalize($schema['items']);
        }

        return $schema;
    }
}
