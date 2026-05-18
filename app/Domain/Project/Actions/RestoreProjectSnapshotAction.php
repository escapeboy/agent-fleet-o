<?php

namespace App\Domain\Project\Actions;

use App\Domain\Project\Models\Project;
use App\Domain\Project\Models\ProjectSnapshot;
use Illuminate\Support\Facades\DB;

/**
 * Restore a Project's configuration from a snapshot.
 * Kanwas-inspired sprint — workspace version history.
 *
 * Guard: refuses while the project has an active run, so a config rollback
 * cannot race a pipeline mid-flight. Milestones are intentionally not restored
 * (they carry run-linked completion state — see CreateProjectSnapshotAction).
 */
class RestoreProjectSnapshotAction
{
    /**
     * @return array{restored: bool, reason: string}
     */
    public function execute(ProjectSnapshot $snapshot, ?string $userId = null): array
    {
        return DB::transaction(function () use ($snapshot, $userId) {
            /** @var Project|null $project */
            $project = Project::withoutGlobalScopes()
                ->lockForUpdate()
                ->find($snapshot->project_id);

            if (! $project) {
                return ['restored' => false, 'reason' => 'Project no longer exists.'];
            }

            if ($project->activeRun() !== null) {
                return [
                    'restored' => false,
                    'reason' => 'Project has an active run. Pause or wait for it to finish before restoring.',
                ];
            }

            $config = $snapshot->snapshot['project'] ?? [];
            $project->update(array_intersect_key(
                $config,
                array_flip(CreateProjectSnapshotAction::PROJECT_CONFIG_KEYS),
            ));

            $scheduleData = $snapshot->snapshot['schedule'] ?? null;
            if (is_array($scheduleData)) {
                $project->schedule()->updateOrCreate(
                    ['project_id' => $project->id],
                    $scheduleData,
                );
            }

            $snapshot->update(['restored_at' => now()]);

            activity()
                ->performedOn($project)
                ->withProperties([
                    'snapshot_id' => $snapshot->id,
                    'snapshot_label' => $snapshot->label,
                    'user_id' => $userId,
                ])
                ->log('project.snapshot_restored');

            return ['restored' => true, 'reason' => 'Project configuration restored.'];
        });
    }
}
