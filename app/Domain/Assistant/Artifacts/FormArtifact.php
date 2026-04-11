<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;

/**
 * Assistant-emitted form for quick user input (one-shot, frozen at generation).
 *
 * Reuses the Gap 1 pattern (LLM-generated form_schema) but lives at the
 * artifact layer instead of the approval layer. The subset of field types
 * is the same 9 types the HumanTaskForm renderer already supports.
 *
 * Unlike Gap 1, these forms do NOT create an ApprovalRequest — they submit
 * back to the assistant as a new user message with the form data encoded.
 */
final class FormArtifact extends BaseArtifact
{
    public const TYPE = 'form';

    private const MAX_FIELDS = 6;

    private const MAX_OPTIONS = 20;

    private const MAX_TITLE_CHARS = 100;

    private const MAX_SUBMIT_LABEL_CHARS = 40;

    private const ALLOWED_FIELD_TYPES = [
        'textarea', 'text', 'number', 'select', 'multi_select',
        'radio_cards', 'checkbox', 'boolean', 'date',
    ];

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    public function __construct(
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $submitLabel,
        public readonly array $fields,
    ) {}

    public function type(): string
    {
        return self::TYPE;
    }

    public static function fromLlmArray(array $raw, array $toolCallsInTurn): ?static
    {
        $title = StringSanitizer::clean($raw['title'] ?? null, self::MAX_TITLE_CHARS);
        if ($title === null) {
            return null;
        }

        $description = StringSanitizer::clean($raw['description'] ?? null, 300);
        $submitLabel = StringSanitizer::cleanOrEmpty($raw['submit_label'] ?? 'Submit', self::MAX_SUBMIT_LABEL_CHARS);

        $rawFields = $raw['fields'] ?? [];
        if (! is_array($rawFields)) {
            return null;
        }

        $fields = [];
        foreach (array_slice($rawFields, 0, self::MAX_FIELDS) as $i => $field) {
            if (! is_array($field)) {
                continue;
            }

            $type = is_string($field['type'] ?? null) ? strtolower($field['type']) : 'textarea';
            if (! in_array($type, self::ALLOWED_FIELD_TYPES, true)) {
                continue;
            }

            $name = StringSanitizer::slugify($field['name'] ?? "field_{$i}", 40) ?? "field_{$i}";
            $label = StringSanitizer::cleanOrEmpty($field['label'] ?? $name, 200);

            $sanitized = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'required' => (bool) ($field['required'] ?? false),
            ];

            if (($help = StringSanitizer::clean($field['help'] ?? null, 200)) !== null) {
                $sanitized['help'] = $help;
            }
            if (($ph = StringSanitizer::clean($field['placeholder'] ?? null, 100)) !== null) {
                $sanitized['placeholder'] = $ph;
            }

            if (in_array($type, ['select', 'multi_select', 'radio_cards'], true)) {
                $options = self::sanitizeOptions($field['options'] ?? []);
                if ($options === []) {
                    // Degrade: no valid options → textarea fallback.
                    $sanitized['type'] = 'textarea';
                } else {
                    $sanitized['options'] = $options;
                }
            }

            if ($type === 'number') {
                if (isset($field['min']) && is_numeric($field['min'])) {
                    $sanitized['min'] = (float) $field['min'];
                }
                if (isset($field['max']) && is_numeric($field['max'])) {
                    $sanitized['max'] = (float) $field['max'];
                }
            }

            $fields[] = $sanitized;
        }

        if ($fields === []) {
            return null;
        }

        return new self(
            title: $title,
            description: $description,
            submitLabel: $submitLabel,
            fields: $fields,
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private static function sanitizeOptions(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $options = [];
        foreach (array_slice($raw, 0, self::MAX_OPTIONS) as $opt) {
            if (! is_array($opt)) {
                continue;
            }
            $value = is_scalar($opt['value'] ?? null) ? (string) $opt['value'] : null;
            $label = StringSanitizer::clean($opt['label'] ?? $value, 100);
            if ($value === null || $value === '' || $label === null) {
                continue;
            }
            $options[] = ['value' => mb_substr($value, 0, 100), 'label' => $label];
        }

        return $options;
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'title' => $this->title,
            'description' => $this->description,
            'submit_label' => $this->submitLabel,
            'fields' => $this->fields,
        ];
    }
}
