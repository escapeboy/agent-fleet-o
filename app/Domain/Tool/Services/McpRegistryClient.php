<?php

namespace App\Domain\Tool\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class McpRegistryClient
{
    private const SMITHERY_BASE = 'https://registry.smithery.ai';

    public function search(string $query = '', int $page = 1, int $pageSize = 24): array
    {
        $cacheKey = 'mcp_registry:'.md5("{$query}:{$page}:{$pageSize}");

        return Cache::remember($cacheKey, 300, function () use ($query, $page, $pageSize) {
            $params = array_filter([
                'q' => $query ?: null,
                'pageSize' => $pageSize,
                'page' => $page,
            ]);

            try {
                $response = Http::timeout(10)
                    ->get(self::SMITHERY_BASE.'/servers', $params);

                if (! $response->successful()) {
                    return ['servers' => [], 'pagination' => null, 'error' => "HTTP {$response->status()}"];
                }

                $data = $response->json();

                return [
                    'servers' => array_map(fn ($s) => [
                        'id' => $s['qualifiedName'] ?? $s['id'] ?? null,
                        'name' => $s['displayName'] ?? $s['qualifiedName'] ?? 'Unknown',
                        'description' => mb_substr($s['description'] ?? '', 0, 500),
                        'icon_url' => self::sanitizeUrl($s['iconUrl'] ?? null),
                        'verified' => (bool) ($s['verified'] ?? false),
                        'use_count' => (int) ($s['useCount'] ?? 0),
                        'remote' => (bool) ($s['remote'] ?? false),
                        'homepage' => self::sanitizeUrl($s['homepage'] ?? null),
                    ], $data['servers'] ?? []),
                    'pagination' => $data['pagination'] ?? null,
                    'error' => null,
                ];
            } catch (\Throwable) {
                return ['servers' => [], 'pagination' => null, 'error' => 'Could not reach MCP registry. Please try again later.'];
            }
        });
    }

    public function getServer(string $qualifiedName): ?array
    {
        $cacheKey = 'mcp_registry_detail:'.md5($qualifiedName);

        return Cache::remember($cacheKey, 600, function () use ($qualifiedName) {
            try {
                $response = Http::timeout(10)
                    ->get(self::SMITHERY_BASE.'/servers/'.urlencode($qualifiedName));

                if (! $response->successful()) {
                    return null;
                }

                $s = $response->json();

                return [
                    'id' => $s['qualifiedName'] ?? null,
                    'name' => $s['displayName'] ?? $s['qualifiedName'] ?? 'Unknown',
                    'description' => mb_substr($s['description'] ?? '', 0, 500),
                    'icon_url' => self::sanitizeUrl($s['iconUrl'] ?? null),
                    'verified' => (bool) ($s['verified'] ?? false),
                    'use_count' => (int) ($s['useCount'] ?? 0),
                    'remote' => (bool) ($s['remote'] ?? false),
                    'homepage' => self::sanitizeUrl($s['homepage'] ?? null),
                    'tools' => array_slice($s['tools'] ?? [], 0, 50),
                    'connections' => $s['connections'] ?? [],
                    'deployment_url' => self::sanitizeUrl($s['deploymentUrl'] ?? null),
                ];
            } catch (\Throwable) {
                return null;
            }
        });
    }

    private static function sanitizeUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return null;
        }

        // Only allow https:// URLs (block javascript:, data:, file:, etc.)
        if (! str_starts_with($url, 'https://') && ! str_starts_with($url, 'http://')) {
            return null;
        }

        return $url;
    }
}
