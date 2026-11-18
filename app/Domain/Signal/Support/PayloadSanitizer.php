<?php

namespace App\Domain\Signal\Support;

/**
 * Makes inbound signal data safe to store: removes NUL characters (rejected by
 * PostgreSQL text/jsonb) and replaces invalid UTF-8 (which makes json_encode fail).
 */
final class PayloadSanitizer
{
    /**
     * Recursively clean strings and string array keys. Other scalars pass through unchanged.
     *
     * @return ($value is array ? array : ($value is string ? string : mixed))
     */
    public static function clean(mixed $value): mixed
    {
        if (is_string($value)) {
            return self::cleanString($value);
        }

        if (is_array($value)) {
            $cleaned = [];
            foreach ($value as $key => $item) {
                $cleanKey = is_string($key) ? self::cleanString($key) : $key;
                $cleaned[$cleanKey] = self::clean($item);
            }

            return $cleaned;
        }

        return $value;
    }

    /**
     * @return ($value is null ? null : string)
     */
    public static function cleanString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return str_replace("\0", '', mb_scrub($value, 'UTF-8'));
    }
}
