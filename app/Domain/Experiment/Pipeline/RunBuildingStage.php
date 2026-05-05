<?php

namespace App\Domain\Experiment\Pipeline;

use App\Domain\Experiment\Actions\TransitionExperimentAction;
use App\Domain\Experiment\Enums\ExperimentStatus;
use App\Domain\Experiment\Enums\ExperimentTaskStatus;
use App\Domain\Experiment\Enums\ExperimentTrack;
use App\Domain\Experiment\Enums\StageStatus;
use App\Domain\Experiment\Enums\StageType;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Experiment\Models\ExperimentStage;
use App\Domain\Experiment\Models\ExperimentTask;
use App\Domain\Website\Actions\CreateWebsiteAction;
use App\Domain\Website\Actions\GenerateWebsiteStructureAction;
use App\Domain\Website\Actions\PublishWebsitePageAction;
use App\Domain\Website\Models\Website;
use App\Models\Artifact;
use App\Models\ArtifactVersion;
use Illuminate\Bus\Batch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;

class RunBuildingStage extends BaseStageJob
{
    public function __construct(string $experimentId, ?string $teamId = null)
    {
        parent::__construct($experimentId, $teamId);
        $this->onQueue('ai-calls');
    }

    protected function expectedState(): ExperimentStatus
    {
        return ExperimentStatus::Building;
    }

    protected function stageType(): StageType
    {
        return StageType::Building;
    }

    /**
     * Override BaseStageJob::handle() entirely to dispatch a Bus::batch
     * instead of building artifacts inline. The stage stays Running while
     * individual jobs run in parallel.
     */
    public function handle(): void
    {
        $experiment = Experiment::withoutGlobalScopes()->find($this->experimentId);

        if (! $experiment) {
            Log::warning('RunBuildingStage: Experiment not found', [
                'experiment_id' => $this->experimentId,
            ]);

            return;
        }

        if ($experiment->status !== $this->expectedState()) {
            Log::info('RunBuildingStage: State guard — not in Building state', [
                'experiment_id' => $experiment->id,
                'actual' => $experiment->status->value,
            ]);

            return;
        }

        $stage = $this->findOrCreateStage($experiment);

        // Idempotency: if this stage already has a batch_id, it was already dispatched.
        if (! empty($stage->output_snapshot['batch_id'])) {
            Log::info('RunBuildingStage: Batch already dispatched for this stage, skipping', [
                'experiment_id' => $experiment->id,
                'batch_id' => $stage->output_snapshot['batch_id'],
            ]);

            return;
        }

        $stage->update([
            'status' => StageStatus::Running,
            'started_at' => now(),
        ]);

        // Get plan from planning stage
        $planningStage = ExperimentStage::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', StageType::Planning)
            ->where('iteration', $experiment->current_iteration)
            ->latest()
            ->first();

        $plan = $planningStage?->output_snapshot ?? [];

        // Web Build track: generate site structure (1 LLM call), then dispatch one page job per page
        if ($experiment->track === ExperimentTrack::WebBuild) {
            [$tasks, $jobs, $websiteId] = $this->prepareWebsiteBatch($experiment, $stage, $plan);
        } else {
            $websiteId = null;
            [$tasks, $jobs] = $this->prepareArtifactBatch($experiment, $plan);
        }

        // Capture primitives for closures
        $experimentId = $experiment->id;
        $stageId = $stage->id;

        $batch = Bus::batch([$jobs])
            ->name("building:{$experimentId}")
            ->onQueue('ai-calls')
            ->then(function () use ($experimentId, $stageId, $websiteId) {
                $stage = ExperimentStage::withoutGlobalScopes()->find($stageId);
                if (! $stage || $stage->status !== StageStatus::Running) {
                    return;
                }

                $builtArtifacts = ExperimentTask::withoutGlobalScopes()
                    ->where('experiment_id', $experimentId)
                    ->where('stage', 'building')
                    ->where('status', ExperimentTaskStatus::Completed)
                    ->get()
                    ->map(fn ($t) => $t->output_data)
                    ->filter()
                    ->values()
                    ->toArray();

                $stageSnapshot = array_merge($stage->output_snapshot ?? [], [
                    'artifacts_built' => $builtArtifacts,
                ]);

                // For web_build: publish the website and create the experiment artifact
                if ($websiteId) {
                    $website = Website::withoutGlobalScopes()->with('pages')->find($websiteId);
                    if ($website) {
                        $publishAction = app(PublishWebsitePageAction::class);
                        foreach ($website->pages as $page) {
                            if ($page->status->value !== 'published') {
                                $publishAction->execute($page);
                            }
                        }
                        $website->update(['status' => 'published']);

                        $publicUrl = url("/api/public/sites/{$website->slug}");
                        $adminUrl = route('websites.show', $website);

                        $artifact = Artifact::withoutGlobalScopes()->create([
                            'team_id' => $website->team_id,
                            'experiment_id' => $experimentId,
                            'type' => 'website',
                            'name' => $website->name,
                            'current_version' => 1,
                            'metadata' => [
                                'website_id' => $website->id,
                                'website_slug' => $website->slug,
                                'public_url' => $publicUrl,
                                'admin_url' => $adminUrl,
                                'pages' => $website->pages->pluck('title', 'slug')->toArray(),
                            ],
                        ]);

                        ArtifactVersion::withoutGlobalScopes()->create([
                            'team_id' => $website->team_id,
                            'artifact_id' => $artifact->id,
                            'version' => 1,
                            'content' => "Website: {$publicUrl}\nAdmin: {$adminUrl}\n\nPages:\n"
                                .$website->pages->map(fn ($p) => "- {$p->title} ({$publicUrl}/{$p->slug})")->implode("\n"),
                            'metadata' => [],
                        ]);

                        $stageSnapshot['website_url'] = $publicUrl;
                        $stageSnapshot['website_artifact_id'] = $artifact->id;
                    }
                }

                $stage->update([
                    'status' => StageStatus::Completed,
                    'completed_at' => now(),
                    'duration_ms' => $stage->started_at ? (int) $stage->started_at->diffInMilliseconds(now()) : null,
                    'output_snapshot' => $stageSnapshot,
                ]);

                $experiment = Experiment::withoutGlobalScopes()->find($experimentId);
                if ($experiment && $experiment->status === ExperimentStatus::Building) {
                    app(TransitionExperimentAction::class)->execute(
                        experiment: $experiment,
                        toState: ExperimentStatus::AwaitingApproval,
                        reason: 'All artifacts built, awaiting approval',
                    );
                }
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($experimentId) {
                Log::warning('RunBuildingStage: Batch has failures', [
                    'experiment_id' => $experimentId,
                    'error' => $e->getMessage(),
                ]);
            })
            ->finally(function () use ($experimentId, $stageId) {
                $stage = ExperimentStage::withoutGlobalScopes()->find($stageId);

                if ($stage && $stage->status === StageStatus::Running) {
                    $failedCount = ExperimentTask::withoutGlobalScopes()
                        ->where('experiment_id', $experimentId)
                        ->where('stage', 'building')
                        ->where('status', ExperimentTaskStatus::Failed)
                        ->count();

                    if ($failedCount > 0) {
                        ExperimentTask::withoutGlobalScopes()
                            ->where('experiment_id', $experimentId)
                            ->where('stage', 'building')
                            ->whereIn('status', [ExperimentTaskStatus::Pending, ExperimentTaskStatus::Queued])
                            ->update([
                                'status' => ExperimentTaskStatus::Skipped,
                                'error' => 'Batch aborted — other tasks failed',
                                'completed_at' => now(),
                            ]);

                        $stage->update([
                            'status' => StageStatus::Failed,
                            'completed_at' => now(),
                            'duration_ms' => $stage->started_at ? (int) $stage->started_at->diffInMilliseconds(now()) : null,
                            'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                                'error' => "{$failedCount} artifact(s) failed to build",
                            ]),
                        ]);

                        $experiment = Experiment::withoutGlobalScopes()->find($experimentId);
                        if ($experiment && $experiment->status === ExperimentStatus::Building) {
                            try {
                                app(TransitionExperimentAction::class)->execute(
                                    experiment: $experiment,
                                    toState: ExperimentStatus::BuildingFailed,
                                    reason: "{$failedCount} artifact(s) failed to build",
                                );
                            } catch (\Throwable $e) {
                                Log::error('RunBuildingStage: Failed to transition to BuildingFailed', [
                                    'experiment_id' => $experimentId,
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }
                    }
                }
            })
            ->dispatch();

        $batchId = $batch->id;

        $stage->update([
            'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                'batch_id' => $batchId,
                'total_tasks' => count($tasks),
            ]),
        ]);

        ExperimentTask::withoutGlobalScopes()
            ->where('experiment_id', $experiment->id)
            ->where('stage', 'building')
            ->whereNull('batch_id')
            ->update(['batch_id' => $batchId]);

        Log::info('RunBuildingStage: Dispatched batch', [
            'experiment_id' => $experiment->id,
            'batch_id' => $batchId,
            'total_tasks' => count($tasks),
            'track' => $experiment->track->value,
        ]);
    }

    /**
     * Prepare tasks and jobs for the web_build track.
     * Makes 1 LLM call to generate the site structure, then creates one task per page.
     *
     * @return array{0: ExperimentTask[], 1: BuildWebsitePageJob[], 2: string} [tasks, jobs, websiteId]
     */
    private function prepareWebsiteBatch(Experiment $experiment, ExperimentStage $stage, array $plan): array
    {
        $prompt = implode("\n\n", array_filter([$experiment->title, $experiment->thesis]));

        $structure = app(GenerateWebsiteStructureAction::class)->execute(
            $experiment->team_id,
            $prompt,
        );

        $website = app(CreateWebsiteAction::class)->execute(
            $experiment->team_id,
            $structure['name'],
        );

        // Store website_id in stage snapshot so the then() callback can publish it
        $stage->update([
            'output_snapshot' => array_merge($stage->output_snapshot ?? [], [
                'website_id' => $website->id,
                'website_slug' => $website->slug,
            ]),
        ]);

        // Build the full page list for nav injection in every page job
        $allPages = array_map(
            fn (array $p) => ['slug' => $p['slug'], 'title' => $p['title']],
            $structure['pages'],
        );
        $publicBaseUrl = url("/api/public/sites/{$website->slug}");

        $tasks = [];
        foreach ($structure['pages'] as $index => $pageSpec) {
            $tasks[] = ExperimentTask::withoutGlobalScopes()->create([
                'team_id' => $experiment->team_id,
                'experiment_id' => $experiment->id,
                'stage' => 'building',
                'name' => "page:{$pageSpec['slug']}",
                'description' => "Generate '{$pageSpec['title']}' page",
                'type' => 'website_page',
                'status' => ExperimentTaskStatus::Pending,
                'sort_order' => $index,
                'input_data' => [
                    'website_id' => $website->id,
                    'page_spec' => $pageSpec,
                    'plan' => $plan,
                ],
            ]);
        }

        $jobs = array_map(
            fn (ExperimentTask $task) => new BuildWebsitePageJob(
                experimentId: $experiment->id,
                taskId: $task->id,
                teamId: $experiment->team_id,
                allPages: $allPages,
                publicBaseUrl: $publicBaseUrl,
            ),
            $tasks,
        );

        return [$tasks, $jobs, $website->id];
    }

    /**
     * Prepare tasks and jobs for standard artifact-building tracks.
     *
     * @return array{0: ExperimentTask[], 1: BuildArtifactJob[]}
     */
    private function prepareArtifactBatch(Experiment $experiment, array $plan): array
    {
        $artifactsToBuild = $plan['artifacts_to_build']
            ?? $plan['plan']['artifacts_to_build']
            ?? [['type' => 'email_template', 'name' => 'outreach_email', 'description' => 'Outreach email for experiment']];

        $tasks = [];
        foreach ($artifactsToBuild as $index => $artifactSpec) {
            $tasks[] = ExperimentTask::withoutGlobalScopes()->create([
                'team_id' => $experiment->team_id,
                'experiment_id' => $experiment->id,
                'stage' => 'building',
                'name' => $artifactSpec['name'] ?? "artifact_{$index}",
                'description' => $artifactSpec['description'] ?? null,
                'type' => $artifactSpec['type'] ?? 'unknown',
                'status' => ExperimentTaskStatus::Pending,
                'sort_order' => $index,
                'input_data' => [
                    'artifact_spec' => $artifactSpec,
                    'plan' => $plan,
                ],
            ]);
        }

        $jobs = array_map(
            fn (ExperimentTask $task) => new BuildArtifactJob(
                experimentId: $experiment->id,
                taskId: $task->id,
                teamId: $experiment->team_id,
            ),
            $tasks,
        );

        return [$tasks, $jobs];
    }

    /**
     * process() is required by BaseStageJob but won't be called since
     * we override handle() entirely.
     */
    protected function process(Experiment $experiment, ExperimentStage $stage): void
    {
        // Not used — handle() is overridden
    }
}
