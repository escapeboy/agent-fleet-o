<?php

declare(strict_types=1);

namespace App\Domain\Shared\Services\PageHelp;

use App\Domain\Project\Enums\ProjectStatus;
use App\Domain\Project\Models\Project;

/**
 * Dynamic page-help for projects.show:
 *   - paused → resume guidance with budget pointer
 *   - archived → restoration guidance
 *   - default (active / draft) → falls back to static help
 */
final class ProjectDetailHelpResolver
{
    /**
     * @param  array<string, mixed>  $routeParameters
     * @return array<string, mixed>|null
     */
    public function __invoke(array $routeParameters): ?array
    {
        $project = $routeParameters['project'] ?? null;
        if (! $project instanceof Project) {
            return null;
        }

        if ($project->status === ProjectStatus::Paused) {
            return [
                'description' => 'This project is paused — scheduled runs are skipped until you resume it. Existing runs continue, but no new ones will start.',
                'steps' => [
                    'Check the recent runs for any failures that may have triggered the pause',
                    'If it was paused due to budget, top up credits in Billing or raise the project cap',
                    'Click Resume to re-enable the schedule',
                ],
                'tips' => [
                    'Pause is reversible; archive is one-way',
                    'Pausing does not cancel in-flight runs — only blocks new ones',
                ],
            ];
        }

        if ($project->status === ProjectStatus::Archived) {
            return [
                'description' => 'This project is archived. Archived projects are read-only — they cannot be reactivated.',
                'steps' => [
                    'Use the runs and metrics tabs for historical analysis',
                    'Create a new project (clone from this one) if you need to start fresh',
                ],
            ];
        }

        return null;
    }
}
