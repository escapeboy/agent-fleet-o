<?php

namespace App\Domain\Experiment\Services;

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
