<?php

namespace Tests\Unit\Livewire;

use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Livewire\Projects\ProjectKanbanPage;
use PHPUnit\Framework\TestCase;

class ProjectKanbanPageTest extends TestCase
{
    public function test_kanban_has_five_columns(): void
    {
        $this->assertCount(5, ProjectKanbanPage::$columns);
    }

    public function test_all_experiment_statuses_covered(): void
    {
        $allStatuses = array_map(fn ($s) => $s->value, ExperimentStatus::cases());
        $covered = [];

        foreach (ProjectKanbanPage::$columns as $col) {
            $covered = array_merge($covered, $col['statuses']);
        }

        // Paused is intentionally not in a column (can appear in any)
        $missing = array_diff($allStatuses, $covered, ['paused']);

        $this->assertEmpty($missing, 'Statuses not covered by Kanban columns: '.implode(', ', $missing));
    }

    public function test_columns_have_required_keys(): void
    {
        foreach (ProjectKanbanPage::$columns as $key => $config) {
            $this->assertArrayHasKey('label', $config, "Column '{$key}' missing label");
            $this->assertArrayHasKey('color', $config, "Column '{$key}' missing color");
            $this->assertArrayHasKey('statuses', $config, "Column '{$key}' missing statuses");
            $this->assertIsArray($config['statuses'], "Column '{$key}' statuses should be array");
        }
    }

    public function test_draft_column_includes_draft_status(): void
    {
        $this->assertContains('draft', ProjectKanbanPage::$columns['draft']['statuses']);
    }

    public function test_completed_column_includes_completed_status(): void
    {
        $this->assertContains('completed', ProjectKanbanPage::$columns['completed']['statuses']);
    }

    public function test_failed_column_includes_failure_statuses(): void
    {
        $failed = ProjectKanbanPage::$columns['failed']['statuses'];

        $this->assertContains('execution_failed', $failed);
        $this->assertContains('killed', $failed);
        $this->assertContains('planning_failed', $failed);
    }
}
