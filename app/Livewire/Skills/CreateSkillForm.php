<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillType;
use App\Infrastructure\AI\Services\ProviderResolver;
use Livewire\Component;

class CreateSkillForm extends Component
{
    public int $step = 1;

    // Step 1: Basics
    public string $name = '';

    public string $description = '';

    public string $type = 'llm';

    public string $riskLevel = 'low';

    // Step 2: Schema
    public array $inputFields = [];

    public array $outputFields = [];

    // Step 3: Configuration (LLM)
    public string $provider = '';

    public string $model = '';

    // Split model (build vs run)
    public bool $splitModelMode = false;

    public string $buildProvider = '';

    public string $buildModel = '';

    public string $runProvider = '';

    public string $runModel = '';

    public string $systemPrompt = '';

    public string $promptTemplate = '';

    public int $maxTokens = 4096;

    public float $temperature = 0.7;

    // Step 3: Configuration (GPU Compute)
    public string $computeProvider = 'runpod';

    public string $computeEndpointId = '';

    public string $computeRoutePath = '/';

    public bool $computeUseSync = true;

    public int $computeTimeout = 90;

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $allowedTypes = 'llm,connector,rule,hybrid,guardrail,multi_model_consensus,code_execution,gpu_compute';
            if (config('browser.enabled', false)) {
                $allowedTypes .= ',browser';
            }

            $this->validate([
                'name' => 'required|min:2|max:255',
                'description' => 'max:1000',
                'type' => "required|in:{$allowedTypes}",
                'riskLevel' => 'required|in:low,medium,high,critical',
            ]);
        }

        $this->step = min(4, $this->step + 1);
    }

    public function prevStep(): void
    {
        $this->step = max(1, $this->step - 1);
    }

    public function addInputField(): void
    {
        $this->inputFields[] = ['name' => '', 'type' => 'string', 'required' => true, 'description' => ''];
    }

    public function removeInputField(int $index): void
    {
        unset($this->inputFields[$index]);
        $this->inputFields = array_values($this->inputFields);
    }

    public function addOutputField(): void
    {
        $this->outputFields[] = ['name' => '', 'type' => 'string', 'required' => false, 'description' => ''];
    }

    public function removeOutputField(int $index): void
    {
        unset($this->outputFields[$index]);
        $this->outputFields = array_values($this->outputFields);
    }

    public function save(): void
    {
        $team = auth()->user()->currentTeam;

        $inputSchema = $this->buildSchema($this->inputFields);
        $outputSchema = $this->buildSchema($this->outputFields);

        if ($this->type === 'gpu_compute') {
            $configuration = array_filter([
                'provider' => $this->computeProvider,
                'endpoint_id' => $this->computeEndpointId ?: null,
                'route_path' => $this->computeRoutePath ?: '/',
                'use_sync' => $this->computeUseSync,
                'timeout_seconds' => $this->computeTimeout,
            ], fn ($v) => $v !== null);
        } elseif ($this->splitModelMode) {
            $configuration = array_filter([
                'model_selection_mode' => 'split',
                'build_model' => array_filter([
                    'provider' => $this->buildProvider ?: null,
                    'model' => $this->buildModel ?: null,
                ]),
                'run_model' => array_filter([
                    'provider' => $this->runProvider ?: null,
                    'model' => $this->runModel ?: null,
                ]),
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'prompt_template' => $this->promptTemplate ?: null,
            ]);
        } else {
            $configuration = array_filter([
                'model_selection_mode' => 'unified',
                'provider' => $this->provider ?: null,
                'model' => $this->model ?: null,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
                'prompt_template' => $this->promptTemplate ?: null,
            ]);
        }

        app(CreateSkillAction::class)->execute(
            teamId: $team->id,
            name: $this->name,
            type: SkillType::from($this->type),
            description: $this->description,
            executionType: ExecutionType::Sync,
            riskLevel: RiskLevel::from($this->riskLevel),
            inputSchema: $inputSchema,
            outputSchema: $outputSchema,
            configuration: $configuration,
            systemPrompt: $this->systemPrompt ?: null,
            requiresApproval: RiskLevel::from($this->riskLevel)->requiresApproval(),
            createdBy: auth()->id(),
        );

        session()->flash('message', 'Skill created successfully!');

        $this->redirect(route('skills.index'));
    }

    private function buildSchema(array $fields): array
    {
        if (empty($fields)) {
            return [];
        }

        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            if (empty($field['name'])) {
                continue;
            }

            $properties[$field['name']] = array_filter([
                'type' => $field['type'] ?? 'string',
                'description' => $field['description'] ?? null,
            ]);

            if ($field['required'] ?? false) {
                $required[] = $field['name'];
            }
        }

        return [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required,
        ];
    }

    public function render()
    {
        $resolver = app(ProviderResolver::class);
        $team = auth()->user()->currentTeam;
        $providers = $resolver->availableProviders($team);

        // Append team's custom endpoints as selectable providers
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

        return view('livewire.skills.create-skill-form', [
            'types' => SkillType::cases(),
            'riskLevels' => RiskLevel::cases(),
            'providers' => $providers,
            'computeProviders' => config('compute_providers.providers', []),
            'canCreate' => true,
            'browserSkillEnabled' => config('browser.enabled', false),
        ])->layout('layouts.app', ['header' => 'Create Skill']);
    }
}
