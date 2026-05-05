<?php

declare(strict_types=1);

namespace App\Infrastructure\Telemetry;

/**
 * Scrubs attribute keys whose names suggest they may contain secrets.
 *
 * Prevents future contributors from accidentally leaking credentials into
 * exported spans. Applied via the TracerProvider wrapper before the SDK
 * records the attribute.
 */
class AttributeRedactor
{
    private const REDACTED_PLACEHOLDER = '[REDACTED]';

    /**
     * @var list<string>
     */
    private array $redactedKeys;

    /**
     * @param  list<string>|null  $redactedKeys
     */
    public function __construct(?array $redactedKeys = null)
    {
        $keys = $redactedKeys ?? config('telemetry.redacted_attributes', []);
        $this->redactedKeys = array_values(array_map('strtolower', $keys));
    }

    public function shouldRedact(string $attributeKey): bool
    {
        $needle = strtolower($attributeKey);

        foreach ($this->redactedKeys as $redacted) {
            if (str_contains($needle, $redacted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function redactMap(array $attributes): array
    {
        foreach ($attributes as $key => $value) {
            if ($this->shouldRedact($key)) {
                $attributes[$key] = self::REDACTED_PLACEHOLDER;
            }
        }

        return $attributes;
    }

    public function sanitize(string $key, mixed $value): mixed
    {
        return $this->shouldRedact($key) ? self::REDACTED_PLACEHOLDER : $value;
    }
}
