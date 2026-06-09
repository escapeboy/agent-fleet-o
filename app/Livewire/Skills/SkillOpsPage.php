<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\AnnotateSkillResponseAction;
use App\Domain\Skill\Actions\CancelSkillBenchmarkAction;
use App\Domain\Skill\Actions\StartSkillBenchmarkAction;
use App\Domain\Skill\Enums\AnnotationRating;
use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Exceptions\BenchmarkAlreadyRunningException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillAnnotation;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Domain\Skill\Models\SkillVersion;
use App\Domain\Skill\Services\MetricGatedImprovementLoopService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Cross-skill operations console surfacing three Skill self-improvement backends
 * that previously had no first-class UI:
 *   1. Improvement Loop — metric-gated loop via MetricGatedImprovementLoopService.
 *   2. Benchmarks       — SkillBenchmark runs (start via StartSkillBenchmarkAction, cancel).
 *   3. Annotations      — RLHF ratings via AnnotateSkillResponseAction.
 */
class SkillOpsPage extends Component
{
    #[Url]
    public string $tab = 'loop';

    /** Selected skill for the active tab's controls. */
    public string $skillId = '';

    // --- Improvement Loop form ---
    public string $loopMetric = 'accuracy';

    public int $loopMaxIterations = 5;

    // --- Benchmark form ---
    public string $benchSkillId = '';

    public string $benchMetricName = 'latency_ms';

    public string $benchMetricDirection = 'maximize';

    public string $benchTestInputs = '[]';

    public int $benchTimeBudget = 3600;

    public int $benchMaxIterations = 50;

    public float $benchComplexityPenalty = 0.01;

    public float $benchImprovementThreshold = 0.0;

    // --- Annotation form ---
    public string $annotateVersionId = '';

    public string $annotateModelId = '';

    public string $annotateInput = '';

    public string $annotateOutput = '';

    public string $annotateRating = 'good';

    public string $annotateNote = '';

    public function mount(): void
    {
        $first = Skill::query()->orderBy('name')->value('id');
        $this->skillId = (string) $first;
        $this->benchSkillId = (string) $first;
    }

    public function startLoop(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'skillId' => 'required|uuid',
            'loopMetric' => 'required|string|max:100',
            'loopMaxIterations' => 'required|integer|min:1|max:50',
        ]);

        $skill = Skill::query()->findOrFail($this->skillId);

        app(MetricGatedImprovementLoopService::class)->run(
            skill: $skill,
            maxIterations: $this->loopMaxIterations,
            metric: $this->loopMetric,
        );

        session()->flash('message', 'Improvement loop started (or already running) for '.$skill->name.'.');
    }

    public function startBenchmark(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'benchSkillId' => 'required|uuid',
            'benchMetricName' => 'required|string|max:100',
            'benchMetricDirection' => 'required|in:maximize,minimize',
            'benchTestInputs' => 'required|json',
            'benchTimeBudget' => 'required|integer|min:60',
            'benchMaxIterations' => 'required|integer|min:1|max:500',
            'benchComplexityPenalty' => 'required|numeric|min:0',
            'benchImprovementThreshold' => 'required|numeric',
        ]);

        $skill = Skill::query()->findOrFail($this->benchSkillId);

        /** @var array<int, mixed> $testInputs */
        $testInputs = json_decode($this->benchTestInputs, true);

        try {
            app(StartSkillBenchmarkAction::class)->execute(
                skill: $skill,
                userId: (string) auth()->id(),
                metricName: $this->benchMetricName,
                testInputs: $testInputs,
                metricDirection: $this->benchMetricDirection,
                timeBudgetSeconds: $this->benchTimeBudget,
                maxIterations: $this->benchMaxIterations,
                complexityPenalty: $this->benchComplexityPenalty,
                improvementThreshold: $this->benchImprovementThreshold,
            );
        } catch (BenchmarkAlreadyRunningException $e) {
            session()->flash('benchmark_error', $e->getMessage());

            return;
        }

        session()->flash('message', 'Benchmark started for '.$skill->name.'.');
    }

    public function cancelBenchmark(string $benchmarkId): void
    {
        Gate::authorize('edit-content');

        $benchmark = SkillBenchmark::query()->findOrFail($benchmarkId);

        app(CancelSkillBenchmarkAction::class)->execute($benchmark);

        session()->flash('message', 'Benchmark cancellation requested.');
    }

    public function submitAnnotation(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'annotateVersionId' => 'required|uuid',
            'annotateModelId' => 'required|string|max:255',
            'annotateInput' => 'required|string|max:20000',
            'annotateOutput' => 'required|string|max:50000',
            'annotateRating' => 'required|in:good,bad',
            'annotateNote' => 'nullable|string|max:2000',
        ]);

        $teamId = (string) auth()->user()->currentTeam->id;

        // Ownership check — the version must belong to a skill in the current team.
        SkillVersion::query()
            ->whereHas('skill', fn ($q) => $q->where('team_id', $teamId))
            ->findOrFail($this->annotateVersionId);

        app(AnnotateSkillResponseAction::class)->execute(
            teamId: $teamId,
            userId: (string) auth()->id(),
            skillVersionId: $this->annotateVersionId,
            modelId: $this->annotateModelId,
            input: $this->annotateInput,
            output: $this->annotateOutput,
            rating: AnnotationRating::from($this->annotateRating),
            note: $this->annotateNote ?: null,
        );

        $this->reset(['annotateInput', 'annotateOutput', 'annotateNote']);

        session()->flash('message', 'Annotation saved.');
    }

    public function render()
    {
        $skills = Skill::query()->orderBy('name')->get(['id', 'name']);

        $benchmarks = SkillBenchmark::query()
            ->with(['skill:id,name', 'iterationLogs'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $annotations = SkillAnnotation::query()
            ->with(['skillVersion:id,skill_id,version', 'skillVersion.skill:id,name', 'user:id,name'])
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();

        $versions = SkillVersion::query()
            ->whereHas('skill', fn ($q) => $q->where('team_id', auth()->user()->currentTeam->id))
            ->with('skill:id,name')
            ->orderByDesc('created_at')
            ->limit(100)
            ->get();

        return view('livewire.skills.skill-ops-page', [
            'skills' => $skills,
            'benchmarks' => $benchmarks,
            'annotations' => $annotations,
            'versions' => $versions,
            'runningBenchmarks' => $benchmarks->where('status', BenchmarkStatus::Running),
        ])->layout('layouts.app', ['header' => 'Skill Ops']);
    }
}
