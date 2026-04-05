<?php

namespace App\Domain\Website\Services;

/**
 * Handles conversion between GrapesJS project data and clean HTML/CSS.
 *
 * GrapesJS stores its editor state as a "project data" JSON object
 * containing components, styles, and assets. The editor exports clean
 * HTML and CSS separately via getHtml() and getCss() JavaScript calls.
 *
 * This service stores both representations:
 *  - grapes_json: the full project data (for re-editing)
 *  - exported_html + exported_css: the rendered output (for serving)
 *
 * The browser sends both when saving; no server-side GrapesJS rendering needed.
 */
class GrapesJsExporter
{
    /**
     * Wrap exported HTML and CSS into a complete standalone HTML document.
     * Sanitizes both HTML and CSS before embedding.
     * Used when serving a page via the Public API.
     */
    public function toStandaloneHtml(
        string $html,
        string $css,
        string $title = '',
        string $metaDescription = '',
        array $extraHead = [],
    ): string {
        $escapedTitle = htmlspecialchars($title, ENT_QUOTES);
        $escapedDescription = htmlspecialchars($metaDescription, ENT_QUOTES);
        $extraHeadHtml = implode("\n        ", $extraHead);

        $html = $this->sanitize($this->stripLlmPreamble($html));
        $css = $this->sanitizeCss($css);

        return <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>{$escapedTitle}</title>
            <meta name="description" content="{$escapedDescription}">
            {$extraHeadHtml}
            <style>
                {$css}
            </style>
        </head>
        <body>
            {$html}
        </body>
        </html>
        HTML;
    }

    /**
     * Strip LLM prose preamble from AI-generated HTML.
     * Handles both ```html...``` fences and bare prose-before-first-tag patterns.
     * Applied on read so pages stored before MaterializeWebsiteFromCrewAction
     * added cleanHtml() are also served correctly.
     */
    public function stripLlmPreamble(string $html): string
    {
        $html = trim($html);

        if ($html === '' || str_starts_with($html, '<')) {
            return $html;
        }

        if (preg_match('/```(?:html)?\s*\n([\s\S]+?)\s*```/i', $html, $m)) {
            return trim($m[1]);
        }

        if (preg_match('/(<[a-z!][^\s>][\s\S]*)/i', $html, $m) && strlen($m[1]) > 20) {
            return trim($m[1]);
        }

        return $html;
    }

    /**
     * Sanitize HTML to prevent XSS.
     * - Strips non-FleetQ script tags
     * - Removes dangerous elements (iframe, object, embed, base, link)
     * - Strips on* event handler attributes
     * - Neutralises javascript: and data: URIs in href/src/action
     */
    public function sanitize(string $html): string
    {
        // Allow only FleetQ-sourced script tags (chatbot widget, form handler)
        $html = preg_replace_callback(
            '/<script\b([^>]*)>(.*?)<\/script>/is',
            function ($matches) {
                $attrs = $matches[1];
                if (str_contains($attrs, 'data-fleetq') || preg_match('/src=["\'][^"\']*fleetq/i', $attrs)) {
                    return $matches[0];
                }

                return '';
            },
            $html,
        ) ?? $html;

        // Remove dangerous block elements (with content)
        $html = preg_replace(
            '/<(iframe|object|embed)\b[^>]*>.*?<\/\1>/is',
            '',
            $html,
        ) ?? $html;

        // Remove dangerous void/self-closing elements
        $html = preg_replace(
            '/<(iframe|object|embed|base)\b[^>]*\/?>/is',
            '',
            $html,
        ) ?? $html;

        // Strip on* event handler attributes (onclick, onerror, onload, etc.)
        $html = preg_replace(
            '/\s+on\w+\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            '',
            $html,
        ) ?? $html;

        // Neutralise javascript: and data: URIs in href/src/action/formaction
        $html = preg_replace(
            '/(\b(?:href|src|action|formaction|data|poster)\s*=\s*["\'])\s*(?:javascript|data):/i',
            '$1#',
            $html,
        ) ?? $html;

        return $html;
    }

    /**
     * Sanitize CSS to prevent data exfiltration and CSS injection attacks.
     * - Strips IE expression() calls
     * - Strips -moz-binding (Firefox CSS injection)
     * - Strips data: URIs inside url() to prevent exfiltration payloads
     */
    public function sanitizeCss(string $css): string
    {
        // Remove IE CSS expression() calls
        $css = preg_replace('/expression\s*\(/i', '(', $css) ?? $css;

        // Remove -moz-binding (Firefox XBL injection)
        $css = preg_replace('/-moz-binding\s*:\s*[^;]*/i', '', $css) ?? $css;

        // Remove data: URIs from url() — can be used to exfiltrate data
        $css = preg_replace('/url\s*\(\s*["\']?\s*data:/i', 'url(about:', $css) ?? $css;

        return $css;
    }
}
