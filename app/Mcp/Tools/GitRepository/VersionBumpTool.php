<?php

namespace App\Mcp\Tools\GitRepository;

use App\Domain\GitRepository\Models\GitRepository;
use App\Domain\GitRepository\Services\GitOperationRouter;
use App\Mcp\Concerns\HasStructuredErrors;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

/**
 * MCP tool for bumping version numbers in repository files.
 *
 * Reads the current version from common version files (package.json, composer.json,
 * VERSION, pyproject.toml), increments according to semver, and commits the change.
 */
#[IsDestructive]
class VersionBumpTool extends Tool
{
    use HasStructuredErrors;

    protected string $name = 'git_version_bump';

    protected string $description = 'Bump the version number in a repository (package.json, composer.json, VERSION, pyproject.toml). Supports semver major/minor/patch increments or setting an explicit version. Commits the change to the specified branch.';

    /** @var string[] */
    private array $versionFiles = [
        'package.json',
        'composer.json',
        'VERSION',
        'version.txt',
        'pyproject.toml',
        '.version',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'repository_id' => $schema->string()
                ->description('Repository UUID')
                ->required(),
            'bump' => $schema->string()
                ->description('Version bump type: major, minor, patch, or explicit')
                ->enum(['major', 'minor', 'patch', 'explicit'])
                ->required(),
            'explicit_version' => $schema->string()
                ->description('Explicit version to set when bump=explicit (e.g. "2.0.0")'),
            'branch' => $schema->string()
                ->description('Branch to commit the version bump to (default: main/master)'),
            'file' => $schema->string()
                ->description('Specific version file to update (default: auto-detect package.json/composer.json/VERSION)'),
            'commit_message' => $schema->string()
                ->description('Custom commit message (default: "chore: bump version to X.Y.Z")'),
        ];
    }

    public function handle(Request $request): Response
    {
        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return $this->permissionDeniedError('No current team.');
        }
        $repo = GitRepository::withoutGlobalScopes()->where('team_id', $teamId)->find($request->get('repository_id'));

        if (! $repo) {
            return $this->notFoundError('repository');
        }

        $bump = $request->get('bump');
        $explicitVersion = $request->get('explicit_version');
        $branch = $request->get('branch', 'main');
        $targetFile = $request->get('file');
        $commitMessage = $request->get('commit_message');

        if ($bump === 'explicit' && ! $explicitVersion) {
            return $this->invalidArgumentError('explicit_version is required when bump=explicit.');
        }

        try {
            $client = app(GitOperationRouter::class)->resolve($repo);

            // Detect version file
            [$filePath, $currentVersion, $fileContent] = $this->detectVersionFile($client, $targetFile);

            if (! $currentVersion) {
                return $this->failedPreconditionError("Could not detect current version in {$filePath}.");
            }

            // Calculate new version
            $newVersion = $bump === 'explicit'
                ? ltrim((string) $explicitVersion, 'v')
                : $this->increment($currentVersion, $bump);

            // Update file content
            $newContent = $this->updateVersion($fileContent, $currentVersion, $newVersion, $filePath);

            // Commit the change
            $message = $commitMessage ?? "chore: bump version to {$newVersion}";
            $sha = $client->commit(
                [['path' => $filePath, 'content' => $newContent]],
                $message,
                $branch,
            );

            return Response::text(json_encode([
                'success' => true,
                'file' => $filePath,
                'previous_version' => $currentVersion,
                'new_version' => $newVersion,
                'branch' => $branch,
                'commit_sha' => $sha,
                'commit_message' => $message,
            ]));
        } catch (\Throwable $e) {
            throw $e;
        }
    }

    /**
     * @return array{0: string, 1: string|null, 2: string}
     */
    private function detectVersionFile(mixed $client, ?string $targetFile): array
    {
        $candidates = $targetFile ? [$targetFile] : $this->versionFiles;

        foreach ($candidates as $file) {
            try {
                $content = $client->readFile($file);
                $version = $this->extractVersion($content, $file);

                if ($version) {
                    return [$file, $version, $content];
                }
            } catch (\Throwable) {
                continue;
            }
        }

        $tried = implode(', ', $candidates);

        throw new \RuntimeException("No version file found. Tried: {$tried}. Use the 'file' parameter to specify explicitly.");
    }

    private function extractVersion(string $content, string $filePath): ?string
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);
        $base = basename($filePath);

        if ($ext === 'json') {
            $data = json_decode($content, true);

            return $data['version'] ?? null;
        }

        if ($base === 'pyproject.toml') {
            if (preg_match('/^version\s*=\s*["\']([^"\']+)["\']/m', $content, $m)) {
                return $m[1];
            }
        }

        // Plain version files (VERSION, version.txt, .version)
        $trimmed = trim($content);

        if (preg_match('/^\d+\.\d+\.\d+/', $trimmed)) {
            return $trimmed;
        }

        return null;
    }

    private function increment(string $version, string $bump): string
    {
        // Strip leading 'v'
        $version = ltrim($version, 'v');
        $parts = explode('.', $version);

        $major = (int) ($parts[0] ?? 0);
        $minor = (int) ($parts[1] ?? 0);
        $patch = (int) ($parts[2] ?? 0);

        return match ($bump) {
            'major' => ($major + 1).'.0.0',
            'minor' => "{$major}.".($minor + 1).'.0',
            'patch' => "{$major}.{$minor}.".($patch + 1),
            default => throw new \InvalidArgumentException("Invalid bump type: {$bump}"),
        };
    }

    private function updateVersion(string $content, string $oldVersion, string $newVersion, string $filePath): string
    {
        $ext = pathinfo($filePath, PATHINFO_EXTENSION);

        if ($ext === 'json') {
            $data = json_decode($content, true);
            $data['version'] = $newVersion;

            return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        }

        if (basename($filePath) === 'pyproject.toml') {
            return preg_replace(
                '/^(version\s*=\s*["\'])'.preg_quote($oldVersion, '/').'(["\'])/m',
                '${1}'.$newVersion.'${2}',
                $content,
            ) ?? $content;
        }

        // Plain version file — replace only the first occurrence on its own line
        return preg_replace(
            '/^'.preg_quote($oldVersion, '/').'$/m',
            $newVersion,
            $content,
            1,
        ) ?? $content;
    }
}
