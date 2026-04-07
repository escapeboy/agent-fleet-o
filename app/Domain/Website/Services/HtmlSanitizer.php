<?php

namespace App\Domain\Website\Services;

use HTMLPurifier;
use HTMLPurifier_Config;

final class HtmlSanitizer
{
    /** HTML5 semantic elements not in HTMLPurifier's default definition. */
    private const HTML5_ELEMENTS = [
        'section', 'article', 'header', 'footer', 'main',
        'nav', 'aside', 'figure', 'figcaption',
    ];

    public static function purify(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();

        // Must be set before maybeGetRawHTMLDefinition
        $config->set('HTML.DefinitionID', 'fleet-website-sanitizer');
        $config->set('HTML.DefinitionRev', 1);

        // Allow common HTML elements and attributes
        $config->set('HTML.Allowed', implode(',', [
            // Structure
            'div[class|id|style]', 'span[class|id|style]', 'p[class|style]', 'br', 'hr',
            // Headings
            'h1[class|style]', 'h2[class|style]', 'h3[class|style]',
            'h4[class|style]', 'h5[class|style]', 'h6[class|style]',
            // Text formatting
            'strong', 'em', 'b', 'i', 'u', 's', 'sup', 'sub', 'small',
            // Links (href restricted to http/https/mailto via URI.AllowedSchemes)
            'a[href|title|target|rel]',
            // Lists
            'ul[class|style]', 'ol[class|style]', 'li[class|style]',
            // Images
            'img[src|alt|width|height|style]',
            // Tables
            'table[class|style]', 'thead', 'tbody', 'tfoot',
            'tr[class|style]', 'th[class|style|scope|colspan|rowspan]', 'td[class|style|colspan|rowspan]',
            // Semantic (registered via HTML5 definitions below)
            'section[class|id|style]', 'article[class|id|style]',
            'header[class|id|style]', 'footer[class|id|style]',
            'main[class|id|style]', 'nav[class|id|style]', 'aside[class|id|style]',
            'figure[class|style]', 'figcaption[class|style]',
            'blockquote', 'pre', 'code',
        ]));

        // Allow style attribute with GrapesJS inline CSS
        $config->set('CSS.AllowedProperties', [
            'color', 'background-color', 'background', 'background-image',
            'font-size', 'font-weight', 'font-family', 'font-style',
            'text-align', 'text-decoration', 'line-height', 'letter-spacing',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'width', 'height', 'max-width', 'min-width', 'max-height', 'min-height',
            'border', 'border-radius', 'border-color', 'border-width', 'border-style',
            'display', 'flex', 'flex-direction', 'align-items', 'justify-content',
            'gap', 'grid-template-columns', 'grid-gap',
            'position', 'top', 'right', 'bottom', 'left', 'z-index',
            'overflow', 'cursor', 'opacity', 'transform',
            'box-shadow', 'text-shadow',
        ]);

        // Only allow http/https/mailto links
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        // on* event handlers are stripped automatically — they are not in HTML.Allowed.
        // javascript: URIs are blocked by URI.AllowedSchemes.

        $config->set('Cache.SerializerPath', storage_path('framework/cache/htmlpurifier'));
        @mkdir(storage_path('framework/cache/htmlpurifier'), 0755, true);

        // Register HTML5 block-level semantic elements — must happen after all set() calls
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            foreach (self::HTML5_ELEMENTS as $element) {
                $def->addElement($element, 'Block', 'Flow', 'Common');
            }
        }

        return (new HTMLPurifier($config))->purify($html);
    }
}
