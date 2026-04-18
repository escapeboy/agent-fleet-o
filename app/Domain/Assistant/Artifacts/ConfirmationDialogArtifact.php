<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;

final class ConfirmationDialogArtifact extends BaseArtifact
{
    public const TYPE = 'confirmation_dialog';

    private const MAX_TITLE_CHARS = 100;

    private const MAX_BODY_CHARS = 500;

    private const MAX_BUTTON_LABEL_CHARS = 40;

    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly string $confirmLabel,
        public readonly string $cancelLabel,
        public readonly bool $destructive,
        public readonly array $onConfirmAction,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?static
    {
        $title = StringSanitizer::clean($raw['title'] ?? null, self::MAX_TITLE_CHARS);
        $body = StringSanitizer::clean($raw['body'] ?? null, self::MAX_BODY_CHARS);
        if ($title === null || $body === null) {
            return null;
        }

        $confirmLabel = StringSanitizer::cleanOrEmpty($raw['confirm_label'] ?? 'Confirm', self::MAX_BUTTON_LABEL_CHARS);
        $cancelLabel = StringSanitizer::cleanOrEmpty($raw['cancel_label'] ?? 'Cancel', self::MAX_BUTTON_LABEL_CHARS);

        $action = self::sanitizeAction($raw['on_confirm'] ?? null);
        if ($action === null) {
            return null;
        }

        return new self(
            title: $title,
            body: $body,
            confirmLabel: $confirmLabel,
            cancelLabel: $cancelLabel,
            destructive: (bool) ($raw['destructive'] ?? false),
            onConfirmAction: $action,
        );
    }

    private static function sanitizeAction(mixed $raw): ?array
    {
        if (! is_array($raw)) {
            return null;
        }

        $type = is_string($raw['type'] ?? null) ? $raw['type'] : null;
        if ($type !== 'invoke_tool') {
            return null;
        }

        $toolName = StringSanitizer::clean($raw['tool_name'] ?? null, 64);
        if ($toolName === null) {
            return null;
        }

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
        ];
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'title' => $this->title,
            'body' => $this->body,
            'confirm_label' => $this->confirmLabel,
            'cancel_label' => $this->cancelLabel,
            'destructive' => $this->destructive,
            'on_confirm' => $this->onConfirmAction,
        ];
    }
}
