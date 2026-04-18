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

    /**
     * Data-* attributes that power the FleetQ Website Builder e-commerce
     * runtime (served as /api/public/sites/{slug}/cart.js). Every element
     * with these attributes is wired up by the cart script — without them
     * Add-to-cart buttons, product search, cart lists, and checkout forms
     * are all silently stripped at save time.
     *
     * Injection surface: plain text attributes, no URL or script context,
     * validated by HTMLPurifier_AttrDef_Text.
     */
    private const DATA_HOOK_ATTRS = [
        // cart state + widgets
        'data-fleetq-cart',
        'data-fleetq-cart-toggle',
        'data-fleetq-cart-count',
        'data-fleetq-cart-total',
        'data-fleetq-cart-items',
        'data-fleetq-clear-cart',
        'data-fleetq-qty',
        'data-fleetq-remove',
        // product catalog
        'data-fleetq-product',
        'data-fleetq-product-search',
        'data-fleetq-add-to-cart',
        // checkout
        'data-fleetq-checkout-form',
        'data-fleetq-checkout-total',
        // product metadata carried on add-to-cart buttons / cards
        'data-product-slug',
        'data-product-title',
        'data-product-price',
        'data-product-currency',
        'data-product-keywords',
    ];

    /**
     * Elements that receive the full data-* hook set. Kept to a closed
     * set of structural + interactive elements.
     */
    private const DATA_HOOK_ELEMENTS = [
        'div', 'span', 'section', 'article', 'header', 'footer', 'main',
        'nav', 'aside', 'a', 'button', 'form', 'input', 'img', 'p', 'ul', 'ol', 'li',
    ];

    public static function purify(string $html): string
    {
        $config = HTMLPurifier_Config::createDefault();

        // Must be set before maybeGetRawHTMLDefinition
        $config->set('HTML.DefinitionID', 'fleet-website-sanitizer');
        $config->set('HTML.DefinitionRev', 8); // bumped: label[for] added
        $config->set('HTML.Forms', true);     // enables HTMLPurifier's built-in Forms module

        // Preserve <!-- fleetq:widget ... --> markers so WebsiteWidgetRenderer
        // can expand them at serve time. Other HTML comments are still stripped.
        $config->set('HTML.AllowedCommentsRegexp', '/^\s*fleetq:/i');

        // Build the HTML.Allowed tokens. Data-* hook attrs are appended to the
        // elements that carry them (div/span/section/article/button/form/
        // input/a/img/nav/p/ul/ol/li). HTMLPurifier requires attributes to
        // appear BOTH in HTML.Allowed AND in the raw definition (addAttribute).
        $dataHookSuffix = '|'.implode('|', self::DATA_HOOK_ATTRS);

        $config->set('HTML.Allowed', implode(',', [
            // Structure
            'div[class|id|style'.$dataHookSuffix.']',
            'span[class|id|style'.$dataHookSuffix.']',
            'p[class|style'.$dataHookSuffix.']',
            'br', 'hr',
            // Headings
            'h1[class|style]', 'h2[class|style]', 'h3[class|style]',
            'h4[class|style]', 'h5[class|style]', 'h6[class|style]',
            // Text formatting
            'strong', 'em', 'b', 'i', 'u', 's', 'sup', 'sub', 'small',
            // Links (href restricted to http/https/mailto via URI.AllowedSchemes)
            'a[href|title|target|rel|class|style'.$dataHookSuffix.']',
            // Lists
            'ul[class|style'.$dataHookSuffix.']',
            'ol[class|style'.$dataHookSuffix.']',
            'li[class|style'.$dataHookSuffix.']',
            // Images
            'img[src|alt|width|height|class|style'.$dataHookSuffix.']',
            // Tables
            'table[class|style]', 'thead', 'tbody', 'tfoot',
            'tr[class|style]', 'th[class|style|scope|colspan|rowspan]', 'td[class|style|colspan|rowspan]',
            // Semantic (registered via HTML5 definitions below)
            'section[class|id|style'.$dataHookSuffix.']',
            'article[class|id|style'.$dataHookSuffix.']',
            'header[class|id|style'.$dataHookSuffix.']',
            'footer[class|id|style'.$dataHookSuffix.']',
            'main[class|id|style'.$dataHookSuffix.']',
            'nav[class|id|style'.$dataHookSuffix.']',
            'aside[class|id|style'.$dataHookSuffix.']',
            'figure[class|style]', 'figcaption[class|style]',
            'blockquote', 'pre', 'code',
            // Forms (registered via definition below — must match addElement() calls)
            'form[method|action|style|class|id'.$dataHookSuffix.']',
            'input[type|name|placeholder|required|value|style|class|id|min|max|step|maxlength|autocomplete'.$dataHookSuffix.']',
            'textarea[name|placeholder|required|rows|cols|style|class|id|maxlength]',
            'button[type|style|class|id'.$dataHookSuffix.']',
            'label[style|class|for]',
            'select[name|required|style|class|id|multiple]',
            'option[value|selected]',
            'fieldset[style|class]',
            'legend[style|class]',
        ]));

        // Allow style attribute with GrapesJS inline CSS.
        //
        // HTMLPurifier 4.19 only whitelists a narrow set of "safe" CSS
        // properties out-of-the-box. Listing an unsupported property in
        // CSS.AllowedProperties throws "Style attribute '…' is not supported"
        // at setup time, killing every purify() call. The properties below
        // are the subset HTMLPurifier 4.19 actually knows about — inline
        // flex/grid/position/transform/border-radius/box-shadow/opacity are
        // all stripped at the CSS level, but Tailwind utility classes
        // (flex, rounded-xl, fixed, z-50, …) still work via class="".
        $config->set('CSS.AllowedProperties', [
            'color', 'background-color', 'background', 'background-image',
            'font-size', 'font-weight', 'font-family', 'font-style',
            'text-align', 'text-decoration', 'line-height', 'letter-spacing',
            'margin', 'margin-top', 'margin-right', 'margin-bottom', 'margin-left',
            'padding', 'padding-top', 'padding-right', 'padding-bottom', 'padding-left',
            'width', 'height', 'max-width', 'min-width', 'max-height', 'min-height',
            'border', 'border-color', 'border-width', 'border-style',
        ]);

        // Only allow http/https/mailto links (and relative URIs, which have no scheme)
        $config->set('URI.AllowedSchemes', ['http' => true, 'https' => true, 'mailto' => true]);

        // on* event handlers are stripped automatically — they are not in HTML.Allowed.
        // javascript: URIs are blocked by URI.AllowedSchemes.

        $config->set('Cache.SerializerPath', storage_path('framework/cache/htmlpurifier'));
        @mkdir(storage_path('framework/cache/htmlpurifier'), 0755, true);

        // Register custom elements — must happen after all set() calls
        if ($def = $config->maybeGetRawHTMLDefinition()) {
            // HTML5 block-level semantic elements
            foreach (self::HTML5_ELEMENTS as $element) {
                $def->addElement($element, 'Block', 'Flow', 'Common');
            }

            // Add HTML5 attributes not in HTMLPurifier's default attribute specs.
            // Form elements themselves are registered by the Forms module (HTML.Forms=true);
            // here we add the HTML5 attributes that module doesn't include.
            $def->addAttribute('input', 'placeholder', 'CDATA');
            $def->addAttribute('input', 'required', 'Bool#required');
            $def->addAttribute('input', 'min', 'CDATA');
            $def->addAttribute('input', 'max', 'CDATA');
            $def->addAttribute('input', 'step', 'CDATA');
            $def->addAttribute('input', 'autocomplete', 'Enum#on,off');
            $def->addAttribute('textarea', 'placeholder', 'CDATA');
            $def->addAttribute('textarea', 'required', 'Bool#required');
            $def->addAttribute('textarea', 'maxlength', 'CDATA');
            $def->addAttribute('select', 'required', 'Bool#required');
            $def->addAttribute('form', 'id', 'ID');
            // The Forms module marks action as required (action*). Override to optional so
            // sanitizer accepts <form> elements without action (e.g. before EnhanceWebsiteNavigationAction
            // injects the real /api/public/... endpoint). URI validation still applies.
            $def->addAttribute('form', 'action', 'URI');
            // Register 'for' as CDATA (plain text) rather than IDREF so HTMLPurifier
            // does not validate that the referenced ID exists in the same document.
            // Standard browser behaviour treats 'for' as a plain string pointing to
            // an input's id, which is exactly what CDATA captures.
            $def->addAttribute('label', 'for', 'CDATA');

            // Register every data-fleetq-* / data-product-* hook as a Text
            // attribute on every element that may carry it. HTMLPurifier
            // requires BOTH this raw-definition registration AND matching
            // entries in HTML.Allowed above — one without the other silently
            // drops the attribute.
            foreach (self::DATA_HOOK_ELEMENTS as $element) {
                foreach (self::DATA_HOOK_ATTRS as $attr) {
                    $def->addAttribute($element, $attr, 'Text');
                }
            }
        }

        return (new HTMLPurifier($config))->purify($html);
    }
}
