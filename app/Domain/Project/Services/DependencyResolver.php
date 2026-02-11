<?php

namespace App\Domain\Project\Services;

use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Project\Enums\ProjectRunStatus;
use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectDependency;
use App\Domain\Project\Models\ProjectRun;
use App\Models\Artifact;

class DependencyResolver
{
    /**
     * Resolve all dependencies for a project into a context array keyed by alias.
     *
     * @return array<string, array{
     *   project_id: string,
     *   project_title: string,
     *   run_id: string,
     *   run_number: int,
     *   artifacts: array,
     *   stage_outputs: array,
     *   metrics_summary: array,
     * }>
     *
     * @throws \RuntimeException if a required dependency has no completed run
     */
    public function resolve(Project $project): array
    {
        $dependencies = $project->dependencies()->ordered()->get();

        if ($dependencies->isEmpty()) {
            return [];
        }

        $context = [];

        foreach ($dependencies as $dependency) {
            $run = $this->resolveRun($dependency);

            if (! $run && $dependency->is_required) {
                throw new \RuntimeException(
                    "Required dependency '{$dependency->alias}' (project: {$dependency->dependsOn->title}) has no completed run."
                );
            }

            if (! $run) {
                continue;
            }

            $context[$dependency->alias] = $this->extractContext($dependency, $run);
        }

        return $context;
    }

    private function resolveRun(ProjectDependency $dependency): ?ProjectRun
    {
        if ($dependency->reference_type === 'specific_run' && $dependency->specific_run_id) {
            return ProjectRun::withoutGlobalScopes()->find($dependency->specific_run_id);
        }

        // Default: latest completed run
        return ProjectRun::withoutGlobalScopes()
            ->where('project_id', $dependency->depends_on_id)
            ->where('status', ProjectRunStatus::Completed)
            ->orderByDesc('run_number')
            ->first();
    }

    private function extractContext(ProjectDependency $dependency, ProjectRun $run): array
    {
        $experiment = $run->experiment;
        $config = $dependency->extract_config;
        $include = $config['include'] ?? ['artifacts', 'stage_outputs'];

        $context = [
            'project_id' => $dependency->depends_on_id,
            'project_title' => $dependency->dependsOn->title,
            'run_id' => $run->id,
            'run_number' => $run->run_number,
            'artifacts' => [],
            'stage_outputs' => [],
            'metrics_summary' => [],
        ];

        if (! $experiment) {
            return $context;
        }

        // Extract artifacts with latest version content
        if (in_array('artifacts', $include)) {
            $context['artifacts'] = Artifact::withoutGlobalScopes()
                ->where('experiment_id', $experiment->id)
                ->get()
                ->map(function (Artifact $artifact) {
                    $latestVersion = $artifact->versions()
                        ->orderByDesc('version')
                        ->first();

                    $content = $latestVersion?->content ?? '';

                    // Truncate very large artifacts to prevent context overflow
                    if (strlen($content) > 5000) {
                        $content = mb_substr($content, 0, 5000) . "\n\n[... truncated, full content available in artifact]";
                    }

                    return [
                        'type' => $artifact->type,
                        'name' => $artifact->name,
                        'version' => $artifact->current_version,
                        'content' => $content,
                    ];
                })
                ->toArray();
        }

        // Extract stage output_snapshots
        if (in_array('stage_outputs', $include)) {
            $context['stage_outputs'] = $experiment->stages()
                ->where('status', StageStatus::Completed)
                ->orderBy('created_at')
                ->get()
                ->mapWithKeys(fn ($stage) => [
                    $stage->stage->value => $stage->output_snapshot ?? [],
                ])
                ->toArray();
        }

        // Extract metrics summary
        if (in_array('metrics', $include)) {
            $context['metrics_summary'] = $experiment->metrics()
                ->selectRaw('type, count(*) as count, avg(value) as avg_value, sum(value) as total_value')
                ->groupBy('type')
                ->get()
                ->mapWithKeys(fn ($m) => [
                    $m->type => [
                        'count' => $m->count,
                        'avg' => round($m->avg_value, 2),
                        'total' => round($m->total_value, 2),
                    ],
                ])
                ->toArray();
        }

        return $context;
    }
}
