<?php

namespace App\Mcp\Services;

use App\Mcp\Servers\AgentFleetServer;
use Laravel\Mcp\Server\Tool;
use ReflectionProperty;
use Throwable;

/**
 * Lazy registry for MCP tools exposed by AgentFleetServer.
 *
 * Provides name-indexed lookup and free-text search, used by the Code Mode
 * portal tools (fleetq_codemode_search, fleetq_codemode_execute) to let an
 * agent discover and dispatch tools without loading every schema up front.
 *
 * Respects each tool's shouldRegister() gate so team-scoped visibility and
 * role gating still apply when tools are invoked via Code Mode.
 *
 * Caches resolution for the lifetime of the service instance; rebuild the
 * container instance to pick up newly-registered tools.
 */
class ToolRegistry
{
    /**
     * @var array<string, Tool>|null
     */
    protected ?array $tools = null;

    /**
     * @return array<string, Tool>
     */
    public function all(): array
    {
        if ($this->tools !== null) {
            return $this->tools;
        }

        $entries = $this->readToolEntries();

        $resolved = [];
        foreach ($entries as $entry) {
            try {
                $tool = is_string($entry) ? app($entry) : $entry;

                if (! $tool instanceof Tool) {
                    continue;
                }

                if (method_exists($tool, 'shouldRegister') && ! $tool->shouldRegister()) {
                    continue;
                }

                $resolved[$tool->name()] = $tool;
            } catch (Throwable) {
                // Skip tools that can't be resolved (e.g. DB-dependent constructors
                // during tests or early boot).
            }
        }

        return $this->tools = $resolved;
    }

    /**
     * Read the merged tool list from AgentFleetServer. Falls back to the
     * class-level default (without plugin-contributed tools) if the server
     * can't be instantiated in the current context — e.g. CLI/tinker where
     * Laravel\Mcp\Server\Contracts\Transport is not bound.
     *
     * @return array<int, class-string<Tool>|Tool>
     */
    protected function readToolEntries(): array
    {
        try {
            $server = app(AgentFleetServer::class);
            $reflection = new ReflectionProperty($server, 'tools');
            $reflection->setAccessible(true);

            /** @var array<int, class-string<Tool>|Tool> $entries */
            $entries = $reflection->getValue($server);

            return $entries;
        } catch (Throwable) {
            $reflection = new ReflectionProperty(AgentFleetServer::class, 'tools');
            $reflection->setAccessible(true);

            /** @var array<int, class-string<Tool>|Tool> $entries */
            $entries = $reflection->getDefaultValue() ?? [];

            return $entries;
        }
    }

    public function find(string $name): ?Tool
    {
        return $this->all()[$name] ?? null;
    }

    /**
     * Free-text search over tool name + description.
     *
     * Scoring is intentionally simple: tokenise the query on whitespace and
     * reward name matches more than description matches. Stable order by
     * descending score then by name.
     *
     * @return array<int, Tool>
     */
    public function search(string $query, int $limit = 10): array
    {
        $query = trim(mb_strtolower($query));
        if ($query === '') {
            return [];
        }

        $terms = preg_split('/\s+/', $query) ?: [];
        $terms = array_values(array_filter($terms, fn ($t) => $t !== ''));
        if ($terms === []) {
            return [];
        }

        $scored = [];
        foreach ($this->all() as $tool) {
            $name = mb_strtolower($tool->name());
            $description = mb_strtolower($tool->description());
            $haystack = $name.' '.$description;

            $score = 0;
            foreach ($terms as $term) {
                if (str_contains($name, $term)) {
                    $score += 10;
                }
                if (str_contains($description, $term)) {
                    $score += 1;
                }
                if (! str_contains($haystack, $term)) {
                    // Any term not found anywhere disqualifies the match.
                    $score = 0;
                    break;
                }
            }

            if ($score > 0) {
                $scored[] = ['score' => $score, 'name' => $tool->name(), 'tool' => $tool];
            }
        }

        usort($scored, function (array $a, array $b): int {
            $cmp = $b['score'] <=> $a['score'];

            return $cmp !== 0 ? $cmp : strcmp($a['name'], $b['name']);
        });

        return array_map(fn (array $entry): Tool => $entry['tool'], array_slice($scored, 0, $limit));
    }
}
