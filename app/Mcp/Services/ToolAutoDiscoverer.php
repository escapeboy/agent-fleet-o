<?php

namespace App\Mcp\Services;

use Laravel\Mcp\Server\Tool;
use ReflectionClass;
use Symfony\Component\Finder\Finder;

/**
 * Discovers MCP Tool classes by scanning the filesystem.
 *
 * The community edition has 600+ Tool classes spread across 50+ subdirectories
 * of `app/Mcp/Tools/`. Maintaining an explicit class-string array in
 * `AgentFleetServer::$tools` was the largest commit hotspot in the 2026-05-07
 * → 2026-05-14 sprint (touched 10 times, every new tool forces one more line).
 *
 * This service eliminates the hotspot AND prevents the "forgot to register"
 * footgun by walking the filesystem at boot time. Returns FQCNs of every
 * concrete subclass of `Laravel\Mcp\Server\Tool` found under the base path.
 *
 * The explicit `$tools` array in `AgentFleetServer` is retained as canonical
 * documentation of the tool taxonomy (grouped + commented); discovered classes
 * not already in the array are appended at boot. Removing a tool from the
 * filesystem still removes it from the registry, so deletes do not require
 * touching the server file either.
 */
class ToolAutoDiscoverer
{
    /**
     * Subdirectories to skip during discovery (abstract bases, integration
     * test fixtures, etc. — anything that lives under `app/Mcp/Tools` but
     * shouldn't appear in the tool catalogue).
     *
     * @var list<string>
     */
    private const EXCLUDED_DIRECTORIES = [
        // currently none — extend here when a known non-tool subdir appears
    ];

    /**
     * Class names to skip even if they live in `app/Mcp/Tools` and extend the
     * Tool base class (abstract helpers, test doubles, etc.).
     *
     * @var list<string>
     */
    private const EXCLUDED_CLASSES = [
        // currently none
    ];

    /**
     * Scan the project for concrete MCP Tool classes and return their FQCNs.
     *
     * Order: alphabetical by FQCN for deterministic output. The MCP protocol
     * does not assign semantic weight to tool order, but stable order keeps
     * tool-list snapshots reproducible across deploys.
     *
     * @return list<class-string<Tool>>
     */
    public function discover(): array
    {
        $baseDir = app_path('Mcp/Tools');

        if (! is_dir($baseDir)) {
            return [];
        }

        $finder = new Finder;
        $finder->files()
            ->in($baseDir)
            ->name('*Tool.php')
            ->ignoreDotFiles(true);

        foreach (self::EXCLUDED_DIRECTORIES as $dir) {
            $finder->notPath($dir);
        }

        $classes = [];
        foreach ($finder as $file) {
            $fqcn = $this->pathToFqcn($file->getRealPath(), $baseDir);
            if ($fqcn === null) {
                continue;
            }

            if (in_array($fqcn, self::EXCLUDED_CLASSES, true)) {
                continue;
            }

            if (! class_exists($fqcn)) {
                continue;
            }

            try {
                $reflection = new ReflectionClass($fqcn);
            } catch (\ReflectionException) {
                continue;
            }

            if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
                continue;
            }

            if (! $reflection->isSubclassOf(Tool::class)) {
                continue;
            }

            $classes[] = $fqcn;
        }

        sort($classes);

        return $classes;
    }

    /**
     * Convert an absolute filesystem path under `app/Mcp/Tools/` to its FQCN.
     *
     * `/.../base/app/Mcp/Tools/Agent/AgentListTool.php` →
     *   `App\Mcp\Tools\Agent\AgentListTool`
     */
    private function pathToFqcn(string $absolutePath, string $baseDir): ?string
    {
        $relative = substr($absolutePath, strlen($baseDir) + 1);
        if ($relative === false || $relative === '') {
            return null;
        }

        $withoutExtension = preg_replace('/\.php$/', '', $relative);
        if ($withoutExtension === null) {
            return null;
        }

        $relativeClass = str_replace(DIRECTORY_SEPARATOR, '\\', $withoutExtension);

        return 'App\\Mcp\\Tools\\'.$relativeClass;
    }
}
