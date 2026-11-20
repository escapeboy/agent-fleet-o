<?php

namespace App\Mcp\Services;

use App\Mcp\Servers\AgentFleetServer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Laravel\Mcp\Server\Tool;
use ReflectionClass;

/**
 * Builds the public MCP tool catalog from AgentFleetServer's declared tools.
 *
 * Names and descriptions are read from class defaults through reflection, so
 * no tool is instantiated. Admin and synthetic tools are left out.
 */
class McpToolCatalog
{
    private const CACHE_KEY = 'mcp-tool-catalog';

    private const CACHE_TTL = 3600;

    private const TOOLS_NAMESPACE = 'App\\Mcp\\Tools\\';

    private const EXCLUDED_NAMESPACES = [
        'App\\Mcp\\Tools\\Admin\\',
        'App\\Mcp\\Tools\\Synthetic\\',
    ];

    /**
     * @return list<array{group: string, slug: string, tools: list<array{name: string, description: string}>}>
     */
    public function groups(): array
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn () => $this->build());
    }

    public function total(): int
    {
        return array_sum(array_map(fn (array $group) => count($group['tools']), $this->groups()));
    }

    /**
     * @return list<array{group: string, slug: string, tools: list<array{name: string, description: string}>}>
     */
    private function build(): array
    {
        try {
            $tools = (new ReflectionClass(AgentFleetServer::class))->getDefaultProperties()['tools'] ?? [];
            if (! is_array($tools)) {
                return [];
            }

            /** @var array<string, array<string, string>> $byGroup */
            $byGroup = [];
            $seen = [];

            foreach ($tools as $class) {
                if (! is_string($class) || ! class_exists($class) || ! is_subclass_of($class, Tool::class)) {
                    continue;
                }

                if (Str::startsWith($class, self::EXCLUDED_NAMESPACES)) {
                    continue;
                }

                $reflection = new ReflectionClass($class);
                if ($reflection->isAbstract()) {
                    continue;
                }

                $defaults = $reflection->getDefaultProperties();
                $name = is_string($defaults['name'] ?? null) ? trim($defaults['name']) : '';
                if ($name === '' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;

                $description = is_string($defaults['description'] ?? null) ? trim($defaults['description']) : '';

                $byGroup[$this->groupLabel($class)][$name] = $description;
            }

            ksort($byGroup);

            $result = [];
            foreach ($byGroup as $label => $entries) {
                ksort($entries);
                $result[] = [
                    'group' => (string) $label,
                    'slug' => Str::slug((string) $label),
                    'tools' => array_map(
                        fn ($name, $description) => ['name' => (string) $name, 'description' => $description],
                        array_keys($entries),
                        array_values($entries),
                    ),
                ];
            }

            return $result;
        } catch (\Throwable) {
            return [];
        }
    }

    private function groupLabel(string $class): string
    {
        if (! Str::startsWith($class, self::TOOLS_NAMESPACE)) {
            return 'General';
        }

        $relative = Str::after($class, self::TOOLS_NAMESPACE);
        if (! str_contains($relative, '\\')) {
            return 'General';
        }

        return Str::headline(Str::before($relative, '\\'));
    }
}
