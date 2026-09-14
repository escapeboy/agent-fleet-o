<?php

namespace App\Domain\Tool\Services;

use Error;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Tool as PrismToolObject;
use Throwable;
use TypeError;

/**
 * Default error handler for Prism tools that do not set their own.
 *
 * Prism's built-in handler puts the raw exception message into the tool result
 * and, for parameter errors, json-encodes every argument the model sent. Neither
 * is capped, so one failing call can push megabytes, the model's own code, or
 * internal detail from an exception message back into the LLM context. This
 * handler names the exception class, keeps a capped message and never echoes
 * argument values.
 *
 * Tools that already have a handler, or that explicitly disabled error handling,
 * are left alone, so ResultAsAnswerException re-throwing and run_tool_program's
 * own capped handler keep working.
 */
final class ToolErrorGuard
{
    public const MAX_MESSAGE_CHARS = 1000;

    private const TRUNCATION_MARKER = '…[truncated]';

    /**
     * Return the tools with a capped default error handler on every tool that has
     * none. Guarded tools are clones; the input objects are never mutated.
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $tools
     * @return array<TKey, mixed>
     */
    public static function apply(array $tools): array
    {
        foreach ($tools as $key => $tool) {
            if (! $tool instanceof PrismToolObject || $tool->failedHandler() !== null) {
                continue;
            }

            $name = $tool->name();
            $expected = array_map('strval', array_keys($tool->parameters()));

            $guarded = clone $tool;
            $guarded->failed(static fn (Throwable $e, array $params): string => self::format($name, $expected, $e));
            $tools[$key] = $guarded;
        }

        return $tools;
    }

    /**
     * @param  array<int, string>  $expectedParameters
     */
    public static function format(string $toolName, array $expectedParameters, Throwable $e): string
    {
        Log::warning('Tool call failed', [
            'tool' => $toolName,
            'exception' => $e::class,
            'message' => self::capMessage($e->getMessage(), 300),
        ]);

        if (self::isParameterError($e)) {
            return sprintf(
                'Parameter validation error in tool %s: %s Expected parameters: [%s]. Check parameter names and types.',
                $toolName,
                self::capMessage($e->getMessage()),
                implode(', ', $expectedParameters),
            );
        }

        return sprintf('Tool %s failed (%s): %s', $toolName, class_basename($e), self::capMessage($e->getMessage()));
    }

    /**
     * Valid UTF-8, no control characters except tab and newline, at most $max
     * characters including the truncation marker.
     */
    public static function capMessage(string $message, int $max = self::MAX_MESSAGE_CHARS): string
    {
        $clean = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', mb_scrub($message, 'UTF-8')));

        if (mb_strlen($clean) <= $max) {
            return $clean;
        }

        return mb_substr($clean, 0, max(0, $max - mb_strlen(self::TRUNCATION_MARKER))).self::TRUNCATION_MARKER;
    }

    /**
     * Same classification Prism uses for its default "validation" message.
     * ArgumentCountError extends TypeError.
     */
    private static function isParameterError(Throwable $e): bool
    {
        return $e instanceof TypeError
            || ($e instanceof Error && str_contains($e->getMessage(), 'Unknown named parameter'));
    }
}
