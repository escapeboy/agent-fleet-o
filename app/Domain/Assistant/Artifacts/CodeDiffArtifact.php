<?php

namespace App\Domain\Assistant\Artifacts;

use App\Domain\Assistant\Artifacts\Support\StringSanitizer;

final class CodeDiffArtifact extends BaseArtifact
{
    public const TYPE = 'code_diff';

    private const MAX_TITLE_CHARS = 120;

    private const MAX_LINE_COUNT = 100;

    private const MAX_TOTAL_CHARS = 5000;

    private const ALLOWED_LANGUAGES = [
        'php', 'ts', 'tsx', 'js', 'jsx', 'py', 'rb', 'go', 'rust', 'rs',
        'yaml', 'yml', 'json', 'md', 'sql', 'blade', 'html', 'css', 'sh',
    ];

    public function __construct(
        public readonly string $title,
        public readonly string $language,
        public readonly string $filePath,
        public readonly string $before,
        public readonly string $after,
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

        $language = is_string($raw['language'] ?? null) ? strtolower($raw['language']) : null;
        if (! in_array($language, self::ALLOWED_LANGUAGES, true)) {
            return null;
        }

        $filePath = StringSanitizer::clean($raw['file_path'] ?? null, 200) ?? '';
        // Extra guard on path: no traversal, no absolute-root, no newlines.
        if (str_contains($filePath, '..') || str_contains($filePath, "\n") || str_contains($filePath, "\r")) {
            return null;
        }

        $before = self::sanitizeCode($raw['before'] ?? '');
        $after = self::sanitizeCode($raw['after'] ?? '');

        if ($before === null || $after === null) {
            return null;
        }

        // Hard total size cap — prevent giant diffs from blowing the message payload.
        if (strlen($before) + strlen($after) > self::MAX_TOTAL_CHARS) {
            return null;
        }

        return new self(
            title: $title,
            language: $language,
            filePath: $filePath,
            before: $before,
            after: $after,
        );
    }

    private static function sanitizeCode(mixed $raw): ?string
    {
        if (! is_string($raw)) {
            return null;
        }

        // Normalize line endings; cap line count + chars; keep whitespace.
        $normalized = str_replace(["\r\n", "\r"], "\n", $raw);
        $lines = explode("\n", $normalized);

        if (count($lines) > self::MAX_LINE_COUNT) {
            $lines = array_slice($lines, 0, self::MAX_LINE_COUNT);
        }

        $joined = implode("\n", $lines);

        // Code is intentionally NOT strip_tags'd because users may legitimately
        // write <tags> in HTML/Blade code. Blade auto-escapes at render time.
        // But we DO strip control chars (except tab + newline).
        $joined = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $joined) ?? '';

        if (mb_strlen($joined) > self::MAX_TOTAL_CHARS) {
            $joined = mb_substr($joined, 0, self::MAX_TOTAL_CHARS);
        }

        return $joined;
    }

    public function toPayload(): array
    {
        return [
            'type' => self::TYPE,
            'title' => $this->title,
            'language' => $this->language,
            'file_path' => $this->filePath,
            'before' => $this->before,
            'after' => $this->after,
        ];
    }
}
