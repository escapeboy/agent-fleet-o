<?php

namespace App\Livewire\Concerns;

/**
 * Drop-in Livewire trait that gives any index page bulk-select + "Ask AI
 * about these" behavior. Consumers set `$selectionKind` (experiment,
 * project, agent, signal, memory, skill, workflow, crew, etc.) and render
 * the `<x-assistant-select-toolbar>` component in their view.
 *
 * Backend from Sprint 5 listens for `assistant-set-selection` and pipes
 * the bundle into the AssistantPanel's context.
 */
trait HasAssistantSelection
{
    /** @var list<string> */
    public array $selectedIds = [];

    /**
     * Entity kind passed to the assistant, e.g. 'experiment'. Override in
     * the consuming component if the class name doesn't map obviously.
     */
    public string $selectionKind = '';

    public function toggleSelection(string $id): void
    {
        if (in_array($id, $this->selectedIds, true)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$id]));
        } else {
            $this->selectedIds[] = $id;
        }
    }

    public function clearSelection(): void
    {
        $this->selectedIds = [];
    }

    /**
     * Dispatch the Sprint 5 event — the AssistantPanel listens for it,
     * sets its context, and auto-opens the chat.
     */
    public function askAssistant(): void
    {
        $ids = array_values(array_unique(array_filter($this->selectedIds, 'is_string')));
        if ($ids === []) {
            return;
        }

        $this->dispatch(
            'assistant-set-selection',
            kind: $this->resolveSelectionKind(),
            ids: $ids,
        );
    }

    public function isSelected(string $id): bool
    {
        return in_array($id, $this->selectedIds, true);
    }

    protected function resolveSelectionKind(): string
    {
        if ($this->selectionKind !== '') {
            return $this->selectionKind;
        }
        // Best-effort inference from the class short name —
        // ExperimentListPage → "experiment", ProjectListPage → "project".
        $short = (new \ReflectionClass($this))->getShortName();
        $stripped = preg_replace('/(List|Browser)?Page$/', '', $short) ?? $short;

        return strtolower((string) $stripped);
    }
}
