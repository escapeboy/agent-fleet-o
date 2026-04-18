<?php

namespace App\Domain\Website\Services;

final class GrapesJsExporter
{
    public static function wrapPage(string $html, string $css, array $meta = []): string
    {
        $isFullDoc = str_starts_with(ltrim($html), '<!DOCTYPE') || str_starts_with(ltrim($html), '<html');

        $metaTags = '';

        if (! empty($meta['title'])) {
            $title = htmlspecialchars($meta['title'], ENT_QUOTES);
            $metaTags .= "<title>{$title}</title>\n";
        }

        if (! empty($meta['description'])) {
            $desc = htmlspecialchars($meta['description'], ENT_QUOTES);
            $metaTags .= "<meta name=\"description\" content=\"{$desc}\">\n";
        }

        if (! empty($meta['og_image'])) {
            $img = htmlspecialchars($meta['og_image'], ENT_QUOTES);
            $metaTags .= "<meta property=\"og:image\" content=\"{$img}\">\n";
        }

        $styleBlock = $css ? "<style>\n{$css}\n</style>\n" : '';

        if ($isFullDoc) {
            // Inject meta + style before </head>
            $inject = $metaTags.$styleBlock;

            return str_replace('</head>', $inject.'</head>', $html);
        }

        return "<!DOCTYPE html>\n<html>\n<head>\n{$metaTags}{$styleBlock}</head>\n<body>\n{$html}\n</body>\n</html>";
    }
}
