<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\CancelSkillBenchmarkAction;
use App\Domain\Skill\Actions\StartSkillBenchmarkAction;
use App\Domain\Skill\Actions\UpdateSkillAction;
use App\Domain\Skill\Enums\BenchmarkStatus;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillStatus;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Exceptions\BenchmarkAlreadyRunningException;
use App\Domain\Skill\Models\Skill;
use App\Domain\Skill\Models\SkillBenchmark;
use App\Domain\Skill\Models\SkillExecution;
use App\Domain\Skill\Models\SkillVersion;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Component;

class SkillDetailPage extends Component
{
    public Skill $skill;

    public string $activeTab = 'overview';

    // Editing state
    public bool $editing = false;

    public string $editName = '';

    public string $editDescription = '';

    public string $editType = 'llm';

    public string $editRiskLevel = 'low';

    public string $editSystemPrompt = '';

    public string $editProvider = '';

    public string $editModel = '';

    public int $editMaxTokens = 4096;

    public float $editTemperature = 0.7;

    public string $editPromptTemplate = '';

    // Benchmark tab state
    public string $benchMetricName = 'latency_ms';

    public string $benchMetricDirection = 'maximize';

    public string $benchTestInputs = '[]';

    public int $benchTimeBudget = 3600;

    public int $benchMaxIterations = 50;

    public float $benchComplexityPenalty = 0.01;

    public float $benchImprovementThreshold = 0.0;

    public function mount(Skill $skill): void
    {
        $this->skill = $skill;
    }

    public function toggleStatus(): void
    {
        Gate::authorize('edit-content');

        $newStatus = $this->skill->status === SkillStatus::Active
            ? SkillStatus::Disabled
            : SkillStatus::Active;

        app(UpdateSkillAction::class)->execute($this->skill, ['status' => $newStatus]);
        $this->skill->refresh();
    }

    public function startEdit(): void
    {
        $this->editName = $this->skill->name;
        $this->editDescription = $this->skill->description ?? '';
        $this->editType = $this->skill->type->value;
        $this->editRiskLevel = $this->skill->risk_level->value;
        $this->editSystemPrompt = $this->skill->system_prompt ?? '';
        $this->editProvider = $this->skill->configuration['provider'] ?? '';
        $this->editModel = $this->skill->configuration['model'] ?? '';
        $this->editMaxTokens = $this->skill->configuration['max_tokens'] ?? 4096;
        $this->editTemperature = $this->skill->configuration['temperature'] ?? 0.7;
        $this->editPromptTemplate = $this->skill->configuration['prompt_template'] ?? '';
        $this->editing = true;
    }

    public function cancelEdit(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    public function save(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'editName' => 'required|min:2|max:255',
            'editDescription' => 'max:1000',
            'editType' => 'required|in:llm,connector,rule,hybrid,guardrail,multi_model_consensus,code_execution'.(config('browser.enabled', false) ? ',browser' : ''),
            'editRiskLevel' => 'required|in:low,medium,high,critical',
            'editSystemPrompt' => 'max:10000',
            'editMaxTokens' => 'integer|min:1|max:32768',
            'editTemperature' => 'numeric|min:0|max:2',
        ]);

        $configuration = array_filter([
            'provider' => $this->editProvider ?: null,
            'model' => $this->editModel ?: null,
            'max_tokens' => $this->editMaxTokens,
            'temperature' => $this->editTemperature,
            'prompt_template' => $this->editPromptTemplate ?: null,
        ]);

        app(UpdateSkillAction::class)->execute($this->skill, [
            'name' => $this->editName,
            'description' => $this->editDescription ?: null,
            'type' => SkillType::from($this->editType),
            'risk_level' => RiskLevel::from($this->editRiskLevel),
            'system_prompt' => $this->editSystemPrompt ?: null,
            'configuration' => $configuration,
            'requires_approval' => RiskLevel::from($this->editRiskLevel)->requiresApproval(),
        ], changelog: 'Updated via admin panel');

        $this->skill->refresh();
        $this->editing = false;

        session()->flash('message', 'Skill updated successfully.');
    }

    public function deleteSkill(): void
    {
        Gate::authorize('edit-content');

        $this->skill->delete();

        session()->flash('message', 'Skill deleted.');
        $this->redirect(route('skills.index'));
    }

    public function startBenchmark(): void
    {
        Gate::authorize('edit-content');

        $this->validate([
            'benchMetricName' => 'required|string|max:100',
            'benchMetricDirection' => 'required|in:maximize,minimize',
            'benchTestInputs' => 'required|json',
            'benchTimeBudget' => 'required|integer|min:60',
            'benchMaxIterations' => 'required|integer|min:1|max:500',
            'benchComplexityPenalty' => 'required|numeric|min:0',
            'benchImprovementThreshold' => 'required|numeric',
        ]);

        $testInputs = json_decode($this->benchTestInputs, true);

        try {
            app(StartSkillBenchmarkAction::class)->execute(
                skill: $this->skill,
                userId: auth()->id(),
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

        session()->flash('message', 'Benchmark loop started successfully.');
    }

    public function cancelBenchmark(): void
    {
        $activeBenchmark = SkillBenchmark::where('skill_id', $this->skill->id)
            ->where('status', BenchmarkStatus::Running)
            ->first();

        if ($activeBenchmark) {
            app(CancelSkillBenchmarkAction::class)->execute($activeBenchmark);
            session()->flash('message', 'Benchmark cancellation requested.');
        }
    }

    /**
     * Refresh the version selector in the Playground tab when a new version is created.
     */
    #[On('skill-version-created')]
    public function onSkillVersionCreated(): void
    {
        $this->skill->refresh();
    }

    public function render()
    {
        $versions = SkillVersion::where('skill_id', $this->skill->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $executions = SkillExecution::where('skill_id', $this->skill->id)
            ->orderByDesc('created_at')
            ->limit(20)
            ->get();

        $resolver = app(ProviderResolver::class);
        $team = auth()->user()->currentTeam;
        $resolvedProvider = $resolver->resolveWithSource(skill: $this->skill, team: $team);
        $providers = $resolver->availableProviders($team);

        foreach ($resolver->customEndpointsForTeam($team) as $ep) {
            $models = [];
            foreach ($ep->credentials['models'] ?? [] as $m) {
                $models[$m] = ['label' => $m, 'input_cost' => 0, 'output_cost' => 0];
            }
            $providers["custom_endpoint:{$ep->name}"] = [
                'name' => $ep->name.' (Custom)',
                'models' => $models,
            ];
        }

        // Enrich local LLM providers with dynamically discovered models
        foreach ($providers as $key => &$providerData) {
            if (! empty($providerData['http_local'])) {
                $providerData['models'] = $resolver->modelsForProvider($key, $team);
            }
        }
        unset($providerData);

        $benchmarks = SkillBenchmark::where('skill_id', $this->skill->id)
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();

        $activeBenchmark = $benchmarks->firstWhere('status', BenchmarkStatus::Running)
            ?? $benchmarks->first();

        if ($activeBenchmark) {
            $activeBenchmark->load('iterationLogs');
        }

        $benchmarkRunning = $benchmarks->contains('status', BenchmarkStatus::Running);

        return view('livewire.skills.skill-detail-page', [
            'versions' => $versions,
            'executions' => $executions,
            'providers' => $providers,
            'resolvedProvider' => $resolvedProvider,
            'benchmarks' => $benchmarks,
            'activeBenchmark' => $activeBenchmark,
            'benchmarkRunning' => $benchmarkRunning,
        ])->layout('layouts.app', ['header' => $this->skill->name]);
    }
}
