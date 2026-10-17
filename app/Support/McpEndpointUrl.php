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

        return self::withPathSegment($url, '/mcp');
    }

    /**
     * Append a path segment (e.g. `/sse`) before any query string or fragment.
     */
    public static function withPathSegment(string $url, string $segment): string
    {
        $pathEnd = strcspn($url, '?#');

        return rtrim(substr($url, 0, $pathEnd), '/').$segment.substr($url, $pathEnd);
    }
}
