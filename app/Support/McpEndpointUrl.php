<?php

namespace App\Support;

class McpEndpointUrl
{
    /**
     * Full MCP endpoint URL for a tool's base URL.
     *
     * Appends `/mcp` to the path unless the path already ends with `/mcp`
     * (after trimming a trailing slash). The query string and fragment stay
     * after the path.
     */
    public static function normalize(string $url): string
    {
        if (str_ends_with(rtrim((string) parse_url($url, PHP_URL_PATH), '/'), '/mcp')) {
            return $url;
        }

        $pathEnd = strcspn($url, '?#');

        return rtrim(substr($url, 0, $pathEnd), '/').'/mcp'.substr($url, $pathEnd);
    }
}
