<?php

namespace Tests\Feature\Mcp;

use App\Mcp\Services\ToolAutoDiscoverer;
use App\Mcp\Tools\Workflow\WorkflowGetTool;
use Laravel\Mcp\Server\Tool;
use Tests\TestCase;

class ToolAutoDiscovererTest extends TestCase
{
    public function test_discovers_a_substantial_number_of_tools(): void
    {
        $discovered = (new ToolAutoDiscoverer)->discover();

        // The community edition has 600+ Tool files. We assert a generous floor
        // (300) so a major reorganization doesn't accidentally drop coverage.
        // The exact count fluctuates as tools are added/removed; this test
        // catches catastrophic discovery failure (returning 0 or 1).
        $this->assertGreaterThan(
            300,
            count($discovered),
            'Auto-discovery returned an unexpectedly small set — likely a path/Finder regression.',
        );
    }

    public function test_every_discovered_class_extends_tool(): void
    {
        $discovered = (new ToolAutoDiscoverer)->discover();

        $invalid = [];
        foreach ($discovered as $class) {
            if (! is_subclass_of($class, Tool::class)) {
                $invalid[] = $class;
            }
        }

        $this->assertSame(
            [],
            $invalid,
            'Auto-discoverer returned classes that do not extend Laravel\\Mcp\\Server\\Tool: '
            .implode(', ', $invalid),
        );
    }

    public function test_no_abstract_classes_or_interfaces_leak_in(): void
    {
        $discovered = (new ToolAutoDiscoverer)->discover();

        $abstracts = [];
        foreach ($discovered as $class) {
            $r = new \ReflectionClass($class);
            if ($r->isAbstract() || $r->isInterface() || $r->isTrait()) {
                $abstracts[] = $class;
            }
        }

        $this->assertSame(
            [],
            $abstracts,
            'Auto-discoverer returned non-concrete types: '.implode(', ', $abstracts),
        );
    }

    public function test_result_is_alphabetically_sorted(): void
    {
        $discovered = (new ToolAutoDiscoverer)->discover();

        $sorted = $discovered;
        sort($sorted);

        $this->assertSame(
            $sorted,
            $discovered,
            'Auto-discoverer output must be deterministically sorted.',
        );
    }

    public function test_no_duplicate_class_strings(): void
    {
        $discovered = (new ToolAutoDiscoverer)->discover();

        $this->assertSame(
            count($discovered),
            count(array_unique($discovered)),
            'Auto-discoverer returned duplicate class strings.',
        );
    }

    public function test_picks_up_a_known_tool(): void
    {
        $discovered = (new ToolAutoDiscoverer)->discover();

        // ToolAnnotationCoverageTest already gates that every tool has the
        // right annotations; here we just verify a single well-known tool
        // is actually found by the discoverer.
        $this->assertContains(
            WorkflowGetTool::class,
            $discovered,
            'Expected WorkflowGetTool to be auto-discovered.',
        );
    }
}
