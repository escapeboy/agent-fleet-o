<?php

namespace App\Domain\Email\Services;

class EmailTemplateInterpolator
{
    /**
     * Replace {{variable}} tokens in an HTML template with values from $data.
     * Values are HTML-escaped to prevent XSS injection.
     */
    public function interpolate(string $html, array $data): string
    {
        return preg_replace_callback('/\{\{(\s*[\w.]+\s*)\}\}/', function (array $matches) use ($data) {
            $key = trim($matches[1]);
            $value = data_get($data, $key, '');

            return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }, $html);
    }
}
