<?php

namespace App\Mcp\Services;

use App\Mcp\Contracts\AutoRegistersAsMcpTool;
use Illuminate\Support\Facades\File;

/**
 * Discovers tagged connectors that opt into MCP exposure (via the
 * AutoRegistersAsMcpTool contract) and synthesizes one Tool subclass per
 * connector under bootstrap/cache/synthetic-mcp-tools/.
 *
 * The cache directory is registered as a PSR-4 namespace prefix in
 * AppServiceProvider so generated files autoload without a composer dump.
 *
 * Activepieces-inspired (build #3, Trendshift top-5 sprint).
 */
class ConnectorMcpRegistrar
{
    /** Relative path from base_path() — kept under bootstrap/cache (already gitignored by Laravel). */
    public const CACHE_DIR = 'bootstrap/cache/synthetic-mcp-tools';

    public const NAMESPACE = 'App\\Mcp\\Tools\\Synthetic\\Generated';

    /**
     * Connector lookup keyed by short hash. Set by ensureToolClassFile() each boot,
     * read by the generated subclass via resolveBinding().
     *
     * @var array<string, AutoRegistersAsMcpTool>
     */
    private static array $bindings = [];

    /**
     * @var list<string> Tags scanned for opt-in connectors. Order matters only for collision logging.
     */
    private array $tags = [
        'fleet.signal.connectors',
        'fleet.outbound.connectors',
        'fleet.integrations',
    ];

    /**
     * Discover all tagged connectors, generate (or refresh) cached PHP class
     * files, and return their FQCNs ready to append to AgentFleetServer::$tools.
     *
     * @return list<class-string>
     */
    public function discoverToolClasses(): array
    {
        $classes = [];
        $seenNames = [];

        foreach ($this->tags as $tag) {
            foreach (app()->tagged($tag) as $service) {
                if (! $service instanceof AutoRegistersAsMcpTool) {
                    continue;
                }

                $name = $service->mcpName();
                if (isset($seenNames[$name])) {
                    \Log::warning('ConnectorMcpRegistrar: duplicate synthetic MCP tool name; skipping later occurrence', [
                        'name' => $name,
                        'kept' => $seenNames[$name],
                        'skipped' => get_class($service),
                    ]);

                    continue;
                }
                $seenNames[$name] = get_class($service);

                $classes[] = $this->ensureToolClassFile($service);
            }
        }

        return $classes;
    }

    /**
     * Write a stable per-connector tool class file to the cache and bind the
     * connector instance behind a static lookup key. Idempotent.
     */
    private function ensureToolClassFile(AutoRegistersAsMcpTool $connector): string
    {
        $key = $this->lookupKey(get_class($connector));
        $shortName = "Tool_{$key}";
        $fqcn = self::NAMESPACE.'\\'.$shortName;

        // Always rebind on each boot — connector instance may be a different singleton this lifecycle.
        self::$bindings[$key] = $connector;

        $cacheDir = base_path(self::CACHE_DIR);
        if (! File::isDirectory($cacheDir)) {
            File::makeDirectory($cacheDir, 0755, true);
        }

        $filePath = "{$cacheDir}/{$shortName}.php";

        if (! File::exists($filePath)) {
            $template = "<?php\n\n".
                'namespace App\\Mcp\\Tools\\Synthetic\\Generated;'."\n\n".
                "class {$shortName} extends \\App\\Mcp\\Tools\\Synthetic\\SyntheticConnectorTool\n".
                "{\n".
                "    protected function connector(): \\App\\Mcp\\Contracts\\AutoRegistersAsMcpTool\n".
                "    {\n".
                '        return \\App\\Mcp\\Services\\ConnectorMcpRegistrar::resolveBinding('."'{$key}'".");\n".
                "    }\n".
                "}\n";

            File::put($filePath, $template);
        }

        if (! class_exists($fqcn, false)) {
            require_once $filePath;
        }

        return $fqcn;
    }

    public static function resolveBinding(string $key): AutoRegistersAsMcpTool
    {
        if (! isset(self::$bindings[$key])) {
            throw new \RuntimeException(
                "Synthetic MCP tool binding '{$key}' missing — connector tag changed since cache was populated. "
                .'Run `php artisan mcp:cache-connector-tools --clear` and reboot.',
            );
        }

        return self::$bindings[$key];
    }

    public function clearCache(): void
    {
        $dir = base_path(self::CACHE_DIR);
        if (File::isDirectory($dir)) {
            File::deleteDirectory($dir);
        }
        self::$bindings = [];
    }

    private function lookupKey(string $connectorClass): string
    {
        return substr(hash('xxh3', $connectorClass), 0, 16);
    }
}
