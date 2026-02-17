<?php

namespace App\Domain\Project\Actions;

use App\Domain\Experiment\Actions\CreateExperimentAction;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Project\Models\Project;

class MaterializeExpandedFeaturesAction
{
    public function __construct(
        private readonly CreateExperimentAction $createExperiment,
    ) {}

    /**
     * Create experiments from expanded features.
     *
     * @param  array  $features  Array of feature objects from ExpandProjectGoalAction
     * @return array Created experiment IDs
     */
    public function execute(Project $project, array $features): array
    {
        $experimentIds = [];

        foreach ($features as $index => $feature) {
            $experiment = $this->createExperiment->execute(
                teamId: $project->team_id,
                title: $feature['title'] ?? 'Feature '.($index + 1),
                thesis: $feature['description'] ?? null,
                track: ExperimentTrack::Build,
                projectId: $project->id,
                constraints: [
                    'expanded_feature_index' => $index,
                    'priority' => $feature['priority'] ?? 'medium',
                    'suggested_agent_role' => $feature['suggested_agent_role'] ?? null,
                    'estimated_credits' => $feature['estimated_credits'] ?? null,
                    'dependencies' => $feature['dependencies'] ?? [],
                ],
                budgetCapCredits: $feature['estimated_credits'] ?? null,
            );

            $experiment->update(['sort_order' => $index]);
            $experimentIds[] = $experiment->id;
        }

        return $experimentIds;
    }
}
