<?php

namespace App\Domain\System\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class VersionCheckService
{
    private const CACHE_KEY = 'system.latest_version';

    private const CACHE_KEY_FULL = 'system.latest_version_full';

    private const CACHE_TTL = 3600; // 1 hour

    public function getInstalledVersion(): string
    {
        return config('app.version', '0.0.0');
    }

    public function getLatestVersion(): ?string
    {
        if (! $this->isCheckEnabled()) {
            return null;
        }

        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->fetchFromGitHub());
    }

    public function isUpdateAvailable(): bool
    {
        $latest = $this->getLatestVersion();

        if ($latest === null) {
            return false;
        }

        return version_compare(
            ltrim($latest, 'v'),
            ltrim($this->getInstalledVersion(), 'v'),
            '>'
        );
    }

    /**
     * @return array{version: string, tag: string, release_url: string|null, release_notes: string|null, published_at: string|null}|null
     */
    public function getUpdateInfo(): ?array
    {
        if (! $this->isUpdateAvailable()) {
            return null;
        }

        $tag = $this->getLatestVersion();
        $full = Cache::get(self::CACHE_KEY_FULL);

        return [
            'version' => ltrim((string) $tag, 'v'),
            'tag' => $tag,
            'release_url' => $full['html_url'] ?? null,
            'release_notes' => $full['body'] ?? null,
            'published_at' => $full['published_at'] ?? null,
        ];
    }

    public function forceRefresh(): ?string
    {
        Cache::forget(self::CACHE_KEY);
        Cache::forget(self::CACHE_KEY_FULL);

        return $this->getLatestVersion();
    }

    public function isCheckEnabled(): bool
    {
        try {
            return (bool) \App\Models\GlobalSetting::get('update_check_enabled', true);
        } catch (\Throwable) {
            return true; // Default to enabled if DB is unavailable
        }
    }

    private function fetchFromGitHub(): ?string
    {
        $repo = config('app.github_repo', 'agent-fleet/agent-fleet');

        try {
            $response = Http::withHeaders([
                'Accept' => 'application/vnd.github+json',
                'X-GitHub-Api-Version' => '2022-11-28',
                'User-Agent' => 'FleetQ/'.config('app.version', '0.0.0'),
            ])
                ->timeout(5)
                ->get("https://api.github.com/repos/{$repo}/releases/latest");

            if (! $response->successful()) {
                return null;
            }

            $data = $response->json();

            if (($data['draft'] ?? false) || ($data['prerelease'] ?? false)) {
                return null;
            }

            $tag = $data['tag_name'] ?? null;

            if ($tag === null || ! preg_match('/^v?\d+\.\d+\.\d+/', $tag)) {
                return null;
            }

            Cache::put(self::CACHE_KEY_FULL, $data, self::CACHE_TTL);

            return $tag;
        } catch (\Throwable $e) {
            Log::warning('FleetQ version check failed: '.$e->getMessage());

            return null;
        }
    }
}
