<?php

namespace App\Domain\Experiment\Services;

use Illuminate\Support\Str;

class ArtifactContentResolver
{
    private const HTML_TYPES = [
        'code', 'frontend', 'backend', 'frontend_landing_page', 'backend_tracking',
        'landing_page', 'email_template', 'html', 'webpage',
    ];

    private const MARKDOWN_TYPES = [
        'seo', 'plan', 'strategy', 'research', 'seo_keyword_pack', 'task_breakdown_plan',
        'sales_strategy_doc', 'product_niche_analysis', 'analysis', 'prompt_snapshot',
        'design', 'documentation', 'report', 'markdown', 'copy', 'document', 'seo_plan',
    ];

    private const JSON_TYPES = [
        'config', 'deployment', 'deployment_config', 'json', 'configuration',
    ];

    public static function category(string $type, ?string $content = null): string
    {
        $type = strtolower(trim($type));

        if (in_array($type, self::HTML_TYPES, true)) {
            return 'html';
        }

        if (in_array($type, self::MARKDOWN_TYPES, true)) {
            return 'markdown';
        }

        if (in_array($type, self::JSON_TYPES, true)) {
            return 'json';
        }

        // Content sniffing fallback
        if ($content !== null) {
            return self::sniffContent($content);
        }

        return 'text';
    }

    public static function mimeType(string $type): string
    {
        return match (self::category($type)) {
            'html' => 'text/html; charset=utf-8',
            'markdown' => 'text/markdown; charset=utf-8',
            'json' => 'application/json; charset=utf-8',
            default => 'text/plain; charset=utf-8',
        };
    }

    public static function extension(string $type): string
    {
        return match (self::category($type)) {
            'html' => 'html',
            'markdown' => 'md',
            'json' => 'json',
            default => 'txt',
        };
    }

    public static function highlightLanguage(string $type): string
    {
        return match (self::category($type)) {
            'html' => 'html',
            'markdown' => 'markdown',
            'json' => 'json',
            default => 'plaintext',
        };
    }

    /**
     * Extract human-readable text from a JSONB output value.
     * Tries known keys (result, text, content, body, output), falls back to full JSON.
     */
    public static function extractReadableText(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return (string) $value;
        }

        // Try common text keys in priority order
        foreach (['result', 'text', 'content', 'body', 'output', 'summary'] as $key) {
            if (isset($value[$key]) && is_string($value[$key])) {
                return $value[$key];
            }
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Extract text from JSONB value and render as HTML via markdown.
     * Handles pipe-delimited tables missing separator rows.
     */
    public static function renderAsHtml(mixed $value, int $limit = 5000): string
    {
        $text = self::extractReadableText($value);
        $text = Str::limit($text, $limit);
        $text = self::normalizeMarkdownTables($text);

        return Str::markdown($text);
    }

    /**
     * Fix pipe-delimited tables that are missing the CommonMark separator row.
     * AI output often produces tables like:
     *   | Metric | 2026 | 2027 |
     *   | ARR    | 1.12 | 3.08 |
     * without the required |---|---|---| separator after the header.
     */
    public static function normalizeMarkdownTables(string $text): string
    {
        $lines = explode("\n", $text);
        $result = [];
        $inTable = false;
        $headerDone = false;
        $headerColCount = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if (preg_match('/^\|.+\|$/', $trimmed)) {
                if (! $inTable) {
                    $inTable = true;
                    $headerDone = false;
                    $headerColCount = substr_count($trimmed, '|') - 1;
                    $result[] = $trimmed;

                    continue;
                }

                if (! $headerDone) {
                    if (preg_match('/^\|[\s:\-|]+$/', $trimmed)) {
                        $headerDone = true;
                        $result[] = $trimmed;
                    } else {
                        $result[] = '|'.implode('|', array_fill(0, max($headerColCount, 1), ' --- ')).'|';
                        $headerDone = true;
                        $result[] = $trimmed;
                    }

                    continue;
                }

                $result[] = $trimmed;
            } else {
                $inTable = false;
                $headerDone = false;
                $result[] = $line;
            }
        }

        return implode("\n", $result);
    }

    private static function sniffContent(string $content): string
    {
        $trimmed = ltrim($content);

        // HTML detection
        if (str_starts_with($trimmed, '<!DOCTYPE') || str_starts_with($trimmed, '<html') || str_starts_with($trimmed, '<HTML')) {
            return 'html';
        }

        // JSON detection
        if ((str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) && json_validate($trimmed)) {
            return 'json';
        }

        // Markdown detection (headings or frontmatter)
        if (preg_match('/^(#{1,6}\s|---\n)/', $trimmed)) {
            return 'markdown';
        }

        return 'text';
    }
}
