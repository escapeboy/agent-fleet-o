<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\AnnotateSkillResponseAction;
use App\Domain\Skill\Actions\GenerateImprovedSkillVersionAction;
use App\Domain\Skill\Actions\SkillPlaygroundRunAction;
use App\Domain\Skill\Enums\AnnotationRating;
use App\Domain\Skill\Exceptions\InsufficientAnnotationsException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillAnnotation;
use App\Domain\Skill\Models\SkillVersion;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Prompt IDE playground for comparing skill outputs across multiple models.
 *
 * Users select up to 3 models, type a test input, click Run, and see output
 * columns fill in as each model completes. They can then annotate each output
 * (thumbs up/down) and trigger AI-assisted version improvement once 3+
 * annotations exist (at least 1 good and 1 bad).
 */
class SkillPlayground extends Component
{
    public string $skillId;

    public string $versionId;

    public string $testInput = '';

    /** @var string[] */
    public array $selectedModels = ['anthropic/claude-sonnet-4-5', 'openai/gpt-4o'];

    /**
     * Keyed by model_id: [output, cost, latency_ms, done, error]
     *
     * @var array<string, array{output: ?string, cost: int, latency_ms: ?int, done: bool, error: ?string}>
     */
    public array $results = [];

    public int $annotationCount = 0;

    public bool $isRunning = false;

    /** Redis run ID used for polling */
    public ?string $runId = null;

    public ?string $errorMessage = null;

    public ?string $successMessage = null;

    public function mount(string $skillId, string $versionId): void
    {
        $this->skillId = $skillId;
        $this->versionId = $versionId;
        $this->refreshAnnotationCount();
    }

    /**
     * Refresh the annotation count for the current version.
     */
    public function refreshAnnotationCount(): void
    {
        $teamId = auth()->user()->current_team_id;
        $this->annotationCount = SkillAnnotation::where('skill_version_id', $this->versionId)
            ->where('team_id', $teamId)
            ->count();
    }

    /**
     * Start a playground run: dispatch sequential AI calls and begin polling.
     */
    public function run(): void
    {
        $this->validate(['testInput' => 'required|string|max:10000']);

        $this->errorMessage = null;
        $this->successMessage = null;
        $this->results = [];

        $skill = Skill::findOrFail($this->skillId);
        $teamId = auth()->user()->current_team_id;
        $userId = auth()->id();

        // Mark all selected models as "pending" so the UI shows loading spinners immediately
        foreach ($this->selectedModels as $model) {
            $this->results[$model] = [
                'output' => null,
                'cost' => 0,
                'latency_ms' => null,
                'done' => false,
                'error' => null,
            ];
        }

        try {
            $this->runId = app(SkillPlaygroundRunAction::class)->execute(
                skill: $skill,
                input: $this->testInput,
                models: $this->selectedModels,
                teamId: $teamId,
                userId: $userId,
            );
            $this->isRunning = true;
        } catch (\Throwable $e) {
            $this->errorMessage = 'Run failed: '.$e->getMessage();
            $this->isRunning = false;
            $this->results = [];
        }
    }

    /**
     * Poll Redis for result updates. Called every 2 s via wire:poll.
     */
    public function pollResults(): void
    {
        if (! $this->isRunning || ! $this->runId) {
            return;
        }

        $teamId = auth()->user()->current_team_id;
        $allDone = true;

        foreach ($this->selectedModels as $model) {
            $key = "skill_playground:{$teamId}:{$this->runId}:{$model}";
            $cached = Cache::get($key);

            if ($cached !== null) {
                $this->results[$model] = $cached;
            } else {
                $allDone = false;
            }
        }

        if ($allDone) {
            $this->isRunning = false;
        }
    }

    /**
     * Persist a thumbs-up or thumbs-down annotation on a model output.
     */
    public function annotate(string $modelId, string $rating, ?string $note = null): void
    {
        $result = $this->results[$modelId] ?? null;

        if (! $result || ! $result['done'] || $result['error'] !== null) {
            return;
        }

        $teamId = auth()->user()->current_team_id;

        app(AnnotateSkillResponseAction::class)->execute(
            teamId: $teamId,
            userId: auth()->id(),
            skillVersionId: $this->versionId,
            modelId: $modelId,
            input: $this->testInput,
            output: $result['output'] ?? '',
            rating: AnnotationRating::from($rating),
            note: $note,
        );

        $this->refreshAnnotationCount();
        $this->successMessage = 'Annotation saved.';
    }

    /**
     * Trigger AI-assisted improvement and emit an event when a new version is created.
     */
    public function generateImprovement(): void
    {
        $this->errorMessage = null;
        $this->successMessage = null;

        $skill = Skill::findOrFail($this->skillId);
        $version = SkillVersion::findOrFail($this->versionId);
        $teamId = auth()->user()->current_team_id;

        try {
            $newVersion = app(GenerateImprovedSkillVersionAction::class)->execute(
                skill: $skill,
                version: $version,
                teamId: $teamId,
                userId: auth()->id(),
            );

            $this->versionId = $newVersion->id;
            $this->refreshAnnotationCount();
            $this->successMessage = 'New version '.$newVersion->version.' generated successfully.';

            // Notify the parent SkillDetailPage to refresh its version selector
            $this->dispatch('skill-version-created');
        } catch (InsufficientAnnotationsException $e) {
            $this->errorMessage = $e->getMessage();
        } catch (\Throwable $e) {
            $this->errorMessage = 'Improvement failed: '.$e->getMessage();
        }
    }

    /**
     * Refresh the version selector when notified by the parent.
     */
    #[On('skill-version-changed')]
    public function onVersionChanged(string $versionId): void
    {
        $this->versionId = $versionId;
        $this->refreshAnnotationCount();
        $this->results = [];
        $this->isRunning = false;
        $this->runId = null;
    }

    public function render(): View
    {
        $skill = Skill::findOrFail($this->skillId);
        $versions = SkillVersion::where('skill_id', $this->skillId)
            ->orderByDesc('created_at')
            ->get(['id', 'version', 'created_at']);

        return view('livewire.skills.skill-playground', [
            'skill' => $skill,
            'versions' => $versions,
        ]);
    }
}
