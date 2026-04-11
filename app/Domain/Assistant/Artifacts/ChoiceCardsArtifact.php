<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;
use App\Domain\Assistant\Artifacts\Support\UrlValidator;

final class ChoiceCardsArtifact extends BaseArtifact
{
    public const TYPE = 'choice_cards';

    private const MAX_OPTIONS = 6;

    private const MAX_QUESTION_CHARS = 200;

    private const MAX_LABEL_CHARS = 100;

    private const MAX_DESCRIPTION_CHARS = 200;

    private const ALLOWED_ACTIONS = ['invoke_tool', 'navigate', 'copy_to_clipboard', 'dismiss'];

    /**
     * @param  list<array{label: string, description: ?string, value: string, action: array<string, mixed>}>  $options
     */
    public function __construct(
        public readonly string $question,
        public readonly array $options,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?static
    {
        $question = StringSanitizer::clean($raw['question'] ?? null, self::MAX_QUESTION_CHARS);
        if ($question === null) {
            return null;
        }

        $rawOptions = $raw['options'] ?? [];
        if (! is_array($rawOptions) || count($rawOptions) < 2) {
            return null;
        }

        $options = [];
        foreach (array_slice($rawOptions, 0, self::MAX_OPTIONS) as $rawOpt) {
            if (! is_array($rawOpt)) {
                continue;
            }

            $label = StringSanitizer::clean($rawOpt['label'] ?? null, self::MAX_LABEL_CHARS);
            if ($label === null) {
                continue;
            }

            $description = StringSanitizer::clean($rawOpt['description'] ?? null, self::MAX_DESCRIPTION_CHARS);
            $value = StringSanitizer::cleanOrEmpty($rawOpt['value'] ?? $label, 100);

            $action = self::sanitizeAction($rawOpt['action'] ?? null, $toolCallsInTurn);
            if ($action === null) {
                continue;
            }

            $options[] = [
                'label' => $label,
                'description' => $description,
                'value' => $value,
                'action' => $action,
            ];
        }

        if (count($options) < 2) {
            return null;
        }

        return new self(question: $question, options: $options);
    }

    private static function sanitizeAction(mixed $raw, array $toolCallsInTurn): ?array
    {
        if (! is_array($raw)) {
            // Actionless card → default to dismiss.
            return ['type' => 'dismiss'];
        }

        $type = is_string($raw['type'] ?? null) ? $raw['type'] : null;
        if (! in_array($type, self::ALLOWED_ACTIONS, true)) {
            return null;
        }

        return match ($type) {
            'invoke_tool' => self::sanitizeInvokeTool($raw, $toolCallsInTurn),
            'navigate' => self::sanitizeNavigate($raw),
            'copy_to_clipboard' => self::sanitizeCopyToClipboard($raw),
            'dismiss' => ['type' => 'dismiss'],
            default => null,
        };
    }

    private static function sanitizeInvokeTool(array $raw, array $toolCallsInTurn): ?array
    {
        $toolName = StringSanitizer::clean($raw['tool_name'] ?? null, 64);
        if ($toolName === null) {
            return null;
        }

        // Sanitize parameters: cap recursion, strip long strings.
        $params = is_array($raw['parameters'] ?? null) ? $raw['parameters'] : [];
        $cleanParams = [];
        foreach (array_slice($params, 0, 10, true) as $key => $val) {
            if (! is_string($key)) {
                continue;
            }
            $cleanKey = StringSanitizer::slugify($key, 40);
            if ($cleanKey === null) {
                continue;
            }
            if (is_scalar($val)) {
                $cleanParams[$cleanKey] = is_string($val)
                    ? StringSanitizer::cleanOrEmpty($val, 500)
                    : $val;
            }
        }

        return [
            'type' => 'invoke_tool',
            'tool_name' => $toolName,
            'parameters' => $cleanParams,
            'destructive' => (bool) ($raw['destructive'] ?? false),
            'confirm_message' => StringSanitizer::clean($raw['confirm_message'] ?? null, 300),
        ];
    }

    private static function sanitizeNavigate(array $raw): ?array
    {
        $url = UrlValidator::normalize($raw['url'] ?? null);
        if ($url === null) {
            return null;
        }

        return [
            'type' => 'navigate',
            'url' => $url,
        ];
    }

    private static function sanitizeCopyToClipboard(array $raw): ?array
    {
        $payload = StringSanitizer::clean($raw['payload'] ?? null, 2000);
        if ($payload === null) {
            return null;
        }

        return [
            'type' => 'copy_to_clipboard',
            'payload' => $payload,
        ];
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'question' => $this->question,
            'options' => $this->options,
        ];
    }
}
