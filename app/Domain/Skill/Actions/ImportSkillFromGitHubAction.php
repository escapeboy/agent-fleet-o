<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Support\SkillKitSpec;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use RuntimeException;

/**
 * Install SkillKit skills directly from a GitHub repository path, mirroring the
 * `npx skills add org/repo/skills` ecosystem convention.
 *
 * Source grammar: `org/repo[/path][@ref]`. A path pointing at a `SKILL.md`
 * imports that one skill; a directory imports every top-level `SKILL.md` plus
 * each immediate subdirectory's `SKILL.md` (the SkillKit `skills/<name>/SKILL.md`
 * layout). Only the fixed host api.github.com is contacted — the caller supplies
 * a repo path, never a URL, so there is no SSRF surface.
 */
class ImportSkillFromGitHubAction
{
    private const MAX_SKILLS = 25;

    private const API = 'https://api.github.com';

    public function __construct(private readonly ImportSkillFromAgentSkillsAction $import) {}

    /**
     * @return array{imported: list<Skill>, failed: array<string, string>, warnings: array<string, list<string>>}
     */
    public function execute(string $teamId, string $source, ?string $token = null, ?string $createdBy = null): array
    {
        [$org, $repo, $path, $ref] = $this->parseSource($source);

        $docs = $this->discover($org, $repo, $path, $ref, $token);
        if ($docs === []) {
            throw new InvalidArgumentException("No SKILL.md found at {$source}.");
        }

        $imported = [];
        $failed = [];
        $warnings = [];

        foreach ($docs as $docPath => $md) {
            try {
                $imported[] = $this->import->execute($teamId, $md, $createdBy);
                $missing = SkillKitSpec::missingSections($md);
                if ($missing !== []) {
                    $warnings[$docPath] = $missing;
                }
            } catch (InvalidArgumentException $e) {
                $failed[$docPath] = $e->getMessage();
            }
        }

        return ['imported' => $imported, 'failed' => $failed, 'warnings' => $warnings];
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string|null}
     */
    private function parseSource(string $source): array
    {
        $source = trim($source);
        $ref = null;

        if (str_contains($source, '@')) {
            [$source, $rawRef] = explode('@', $source, 2);
            $ref = trim($rawRef) !== '' ? trim($rawRef) : null;
        }

        $segments = array_values(array_filter(
            explode('/', trim($source, '/')),
            static fn (string $s): bool => $s !== '',
        ));

        if (count($segments) < 2) {
            throw new InvalidArgumentException('GitHub source must be "org/repo[/path][@ref]".');
        }

        return [$segments[0], $segments[1], implode('/', array_slice($segments, 2)), $ref];
    }

    /**
     * Resolve a path into a map of GitHub file path => SKILL.md text.
     *
     * @return array<string, string>
     */
    private function discover(string $org, string $repo, string $path, ?string $ref, ?string $token): array
    {
        $entry = $this->contents($org, $repo, $path, $ref, $token);

        // A single file is returned as an object carrying its own `type`.
        if (isset($entry['type'])) {
            if ($entry['type'] !== 'file' || ! $this->isSkillFile((string) ($entry['name'] ?? ''))) {
                throw new InvalidArgumentException("{$path} is not a SKILL.md file.");
            }

            return [(string) ($entry['path'] ?? $path) => $this->decodeFile($entry, $token)];
        }

        // A directory is returned as a list of entries.
        $docs = [];

        foreach ($entry as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'file' && $this->isSkillFile((string) ($item['name'] ?? ''))) {
                $docs[(string) $item['path']] = $this->fetchRaw((string) ($item['download_url'] ?? ''), $token);
            }
        }

        foreach ($entry as $item) {
            if (count($docs) >= self::MAX_SKILLS) {
                break;
            }
            if (! is_array($item) || ($item['type'] ?? '') !== 'dir') {
                continue;
            }

            foreach ($this->contents($org, $repo, (string) $item['path'], $ref, $token) as $file) {
                if (is_array($file) && ($file['type'] ?? '') === 'file' && $this->isSkillFile((string) ($file['name'] ?? ''))) {
                    $docs[(string) $file['path']] = $this->fetchRaw((string) ($file['download_url'] ?? ''), $token);
                }
            }
        }

        return array_slice($docs, 0, self::MAX_SKILLS, true);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function contents(string $org, string $repo, string $path, ?string $ref, ?string $token): array
    {
        $url = self::API."/repos/{$org}/{$repo}/contents".($path !== '' ? '/'.ltrim($path, '/') : '');

        $response = $this->client($token)->get($url, $ref !== null ? ['ref' => $ref] : []);

        if ($response->status() === 404) {
            throw new InvalidArgumentException("GitHub path not found: {$org}/{$repo}/{$path}".($ref !== null ? "@{$ref}" : ''));
        }
        if ($response->failed()) {
            throw new RuntimeException("GitHub API error ({$response->status()}) for {$org}/{$repo}.");
        }

        return (array) $response->json();
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function decodeFile(array $entry, ?string $token): string
    {
        if (($entry['encoding'] ?? '') === 'base64' && isset($entry['content'])) {
            return (string) base64_decode((string) preg_replace('/\s+/', '', (string) $entry['content']), true);
        }

        return $this->fetchRaw((string) ($entry['download_url'] ?? ''), $token);
    }

    private function fetchRaw(string $url, ?string $token): string
    {
        if ($url === '') {
            throw new InvalidArgumentException('GitHub did not provide a download URL for the file.');
        }

        $response = $this->client($token)->get($url);
        if ($response->failed()) {
            throw new RuntimeException("Failed to download {$url} ({$response->status()}).");
        }

        return $response->body();
    }

    private function client(?string $token): PendingRequest
    {
        $headers = [
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'FleetQ',
        ];
        if ($token !== null && $token !== '') {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return Http::withHeaders($headers)->timeout(15);
    }

    private function isSkillFile(string $name): bool
    {
        return strtolower($name) === 'skill.md';
    }
}
