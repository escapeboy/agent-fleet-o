<?php

namespace App\Domain\System\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ChangelogParser
{
    /**
     * Parse CHANGELOG.md into structured entries.
     *
     * @return array<int, array{version: string, date: ?Carbon, sections: array<string, string>, content_html: string, id: string}>
     */
    public function parse(?string $path = null): array
    {
        $path ??= base_path('CHANGELOG.md');

        if (! file_exists($path)) {
            return [];
        }

        $mtime = filemtime($path);
        $cacheKey = 'changelog.parsed.'.$mtime;

        return Cache::rememberForever($cacheKey, fn () => $this->doParse($path));
    }

    /**
     * Get the date of the most recent released entry.
     */
    public function getLatestEntryDate(): ?Carbon
    {
        $entries = $this->parse();

        if (empty($entries)) {
            return null;
        }

        return $entries[0]['date'] ?? null;
    }

    /**
     * Check if there are new entries since the given timestamp.
     */
    public function hasNewEntries(?\DateTimeInterface $since): bool
    {
        if ($since === null) {
            return ! empty($this->parse());
        }

        $latest = $this->getLatestEntryDate();

        if ($latest === null) {
            return false;
        }

        return $latest->greaterThan($since);
    }

    /**
     * @return array<int, array{version: string, date: ?Carbon, sections: array<string, string>, content_html: string, id: string}>
     */
    private function doParse(string $path): array
    {
        $content = file_get_contents($path);
        $entries = [];

        // Split by version headers: ## [version] - date OR ## YYYY-MM-DD
        $pattern = '/^## \s*(.+)$/m';
        $parts = preg_split($pattern, $content, -1, PREG_SPLIT_DELIM_CAPTURE);

        // parts[0] = preamble (before first ##), then alternating header/body pairs
        for ($i = 1; $i < count($parts); $i += 2) {
            $header = trim($parts[$i]);
            $body = $parts[$i + 1] ?? '';

            // Skip [Unreleased] section
            if (preg_match('/\[unreleased\]/i', $header)) {
                continue;
            }

            // Parse header: [version] - date OR just date
            $version = null;
            $date = null;
            $id = '';

            if (preg_match('/\[([^\]]+)\]\s*-\s*(\d{4}-\d{2}-\d{2})/', $header, $m)) {
                // Format: [1.1.0] - 2026-02-28
                $version = $m[1];
                $date = Carbon::parse($m[2]);
                $id = 'v'.ltrim($version, 'v');
            } elseif (preg_match('/(\d{4}-\d{2}-\d{2})/', $header, $m)) {
                // Format: 2026-02-15
                $date = Carbon::parse($m[1]);
                $id = $m[1];
            } else {
                continue; // Skip unparseable headers
            }

            // Extract sub-sections (### Added, ### Fixed, etc.)
            $sections = $this->extractSections($body);

            // Render body as HTML
            $contentHtml = Str::markdown(trim($body));

            $entries[] = [
                'version' => $version,
                'date' => $date,
                'sections' => $sections,
                'content_html' => $contentHtml,
                'id' => $id,
            ];
        }

        return $entries;
    }

    /**
     * Extract ### sub-section names from the body.
     *
     * @return array<string, string> section name => category badge type
     */
    private function extractSections(string $body): array
    {
        $sections = [];
        $categoryMap = [
            'Added' => 'new',
            'Changed' => 'improved',
            'Fixed' => 'fixed',
            'Security' => 'security',
            'Deprecated' => 'deprecated',
            'Removed' => 'removed',
        ];

        preg_match_all('/^### \s*(.+)$/m', $body, $matches);

        foreach ($matches[1] as $section) {
            $name = trim($section);
            $sections[$name] = $categoryMap[$name] ?? 'other';
        }

        return $sections;
    }
}
