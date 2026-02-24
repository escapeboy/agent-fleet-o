<?php

namespace App\Domain\Trigger\Services;

use App\Domain\Signal\Models\Signal;

/**
 * Maps signal fields to project input_data using a TriggerRule's input_mapping spec.
 *
 * Mapping format:
 *   { "targetKey": "source.dot.path" }
 *
 * Example:
 *   { "ticket_title": "metadata.title", "severity": "metadata.severity" }
 */
class SignalInputMapper
{
    /**
     * @param  array<string, string>|null  $inputMapping
     * @return array<string, mixed>
     */
    public function map(?array $inputMapping, Signal $signal): array
    {
        if (empty($inputMapping)) {
            return [];
        }

        $result = [];
        $payload = $signal->payload ?? [];

        foreach ($inputMapping as $targetKey => $sourcePath) {
            $result[$targetKey] = $this->resolvePath((string) $sourcePath, $payload);
        }

        // Also inject top-level signal metadata for convenience
        $result['_signal_id'] = $signal->id;
        $result['_signal_source'] = $signal->source_type;
        $result['_signal_received_at'] = $signal->received_at?->toIso8601String();

        return $result;
    }

    private function resolvePath(string $path, array $data): mixed
    {
        // Support literal values (no dots = top-level key or literal)
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
}
