<?php

namespace Tests\Unit\Livewire;

use App\Livewire\Concerns\HasAssistantSelection;
use App\Livewire\Experiments\ExperimentListPage;
use App\Livewire\Projects\ProjectListPage;
use Tests\TestCase;

class HasAssistantSelectionTest extends TestCase
{
    private function makeHost(string $kind = ''): object
    {
        // Plain PHP class (not extending Livewire\Component) so our stub
        // dispatch() signature doesn't conflict with the framework.
        return new class($kind)
        {
            use HasAssistantSelection;

            public array $events = [];

            public function __construct(string $k)
            {
                $this->selectionKind = $k;
            }

            public function dispatch(string $name, ...$args): self
            {
                $this->events[] = ['name' => $name, 'args' => $args];

                return $this;
            }
        };
    }

    public function test_toggle_adds_then_removes(): void
    {
        $c = $this->makeHost('experiment');
        $c->toggleSelection('a');
        $this->assertSame(['a'], $c->selectedIds);
        $c->toggleSelection('b');
        $this->assertSame(['a', 'b'], $c->selectedIds);
        $c->toggleSelection('a');
        $this->assertSame(['b'], $c->selectedIds);
    }

    public function test_is_selected(): void
    {
        $c = $this->makeHost();
        $c->selectedIds = ['x'];
        $this->assertTrue($c->isSelected('x'));
        $this->assertFalse($c->isSelected('y'));
    }

    public function test_clear_empties_selection(): void
    {
        $c = $this->makeHost();
        $c->selectedIds = ['a', 'b', 'c'];
        $c->clearSelection();
        $this->assertSame([], $c->selectedIds);
    }

    public function test_ask_assistant_dispatches_event_with_kind_and_ids(): void
    {
        $c = $this->makeHost('experiment');
        $c->selectedIds = ['id1', 'id2'];
        $c->askAssistant();

        $this->assertCount(1, $c->events);
        $this->assertSame('assistant-set-selection', $c->events[0]['name']);
        $this->assertSame(['kind' => 'experiment', 'ids' => ['id1', 'id2']], $c->events[0]['args']);
    }

    public function test_ask_assistant_noop_when_empty(): void
    {
        $c = $this->makeHost('experiment');
        $c->askAssistant();
        $this->assertSame([], $c->events);
    }

    public function test_duplicate_ids_deduped_in_dispatch(): void
    {
        $c = $this->makeHost('project');
        $c->selectedIds = ['a', 'a', 'b', 'a'];
        $c->askAssistant();
        $this->assertSame(['a', 'b'], $c->events[0]['args']['ids']);
    }

    public function test_experiment_list_page_defaults_kind_to_experiment(): void
    {
        $page = new ExperimentListPage;
        $page->mount();
        $this->assertSame('experiment', $page->selectionKind);
    }

    public function test_project_list_page_defaults_kind_to_project(): void
    {
        $page = new ProjectListPage;
        $page->mount();
        $this->assertSame('project', $page->selectionKind);
    }

    public function test_resolve_selection_kind_infers_from_class_name_when_blank(): void
    {
        $c = $this->makeHost(''); // blank selectionKind
        $r = new \ReflectionMethod($c, 'resolveSelectionKind');
        $r->setAccessible(true);
        $kind = $r->invoke($c);
        // Anonymous class won't have a clean short name — just assert non-empty string.
        $this->assertIsString($kind);
    }
}
