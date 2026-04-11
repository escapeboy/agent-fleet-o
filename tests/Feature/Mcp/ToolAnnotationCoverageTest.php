<?php

namespace Tests\Feature\Mcp;

use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;
use ReflectionClass;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Enforces Claude Connectors Directory submission requirement:
 * every MCP tool must declare readOnlyHint or destructiveHint.
 *
 * @see https://claude.com/docs/connectors/building/submission
 */
class ToolAnnotationCoverageTest extends TestCase
{
    public function test_every_mcp_tool_has_read_only_or_destructive_annotation(): void
    {
        $missing = [];

        foreach ($this->discoverToolClasses() as $class) {
            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Tool::class)) {
                continue;
            }

            $hasReadOnly = ! empty($reflection->getAttributes(IsReadOnly::class));
            $hasDestructive = ! empty($reflection->getAttributes(IsDestructive::class));

            if (! $hasReadOnly && ! $hasDestructive) {
                $missing[] = $class;
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Every MCP tool must declare #[IsReadOnly] or #[IsDestructive] '
            ."(Claude Connectors Directory submission requirement).\nMissing:\n"
            .implode("\n", $missing)
        );
    }

    /**
     * @return iterable<class-string>
     */
    private function discoverToolClasses(): iterable
    {
        $finder = (new Finder)
            ->files()
            ->in(app_path('Mcp/Tools'))
            ->name('*Tool.php');

        foreach ($finder as $file) {
            $relative = str_replace(
                [app_path().'/', '/', '.php'],
                ['', '\\', ''],
                $file->getRealPath()
            );
            $class = 'App\\'.$relative;

            if (class_exists($class)) {
                yield $class;
            }
        }
    }
}
