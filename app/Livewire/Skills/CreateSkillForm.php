<?php

namespace App\Livewire\Skills;

use App\Domain\Skill\Actions\CreateSkillAction;
use App\Domain\Skill\Actions\RegisterBorunaToolAction;
use App\Domain\Skill\Enums\ExecutionType;
use App\Domain\Skill\Enums\RiskLevel;
use App\Domain\Skill\Enums\SkillType;
use App\Domain\Skill\Services\BorunaPlatformService;
use App\Infrastructure\AI\Services\ProviderResolver;
use Illuminate\Support\Facades\Gate;
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

    // Step 3: Configuration (RunPod Endpoint — serverless)
    public string $runpodEndpointId = '';

    public string $runpodRoutePath = '/run';

    public bool $runpodUseSync = true;

    public int $runpodTimeout = 90;

    // Step 3: Configuration (RunPod Pod — full GPU pod)
    public string $runpodDockerImage = '';

    public string $runpodGpuType = 'NVIDIA RTX 4090';

    public int $runpodGpuCount = 1;

    public int $runpodContainerDiskGb = 20;

    public int $runpodEstimatedMinutes = 10;

    // Step 3: Configuration (Boruna Script)
    public string $borunaScript = '';

    public int $borunaScriptTimeout = 60;

    // Step 3: Configuration (Supabase Edge Function)
    public string $supabaseProjectUrl = '';

    public string $supabaseFunctionName = '';

    public string $supabaseAnonKey = '';

    // Step 3: Configuration (Multi-Model Consensus)
    public array $consensusModels = [];

    public float $consensusThreshold = 0.5;

    public string $consensusAggregation = 'majority';

    /**
     * Self-serve registration of the bundled Boruna mcp_stdio binary as a Tool
     * for the current team. Invoked from the boruna_script panel banner when
     * the platform reports `ready_to_enable` status.
     */
    public function enableBoruna(RegisterBorunaToolAction $action): void
    {
        if (! Gate::allows('manage-team', auth()->user()->currentTeam)) {
            $this->addError('borunaScript', 'You do not have permission to enable Boruna for this team. Ask a team admin or owner.');

            return;
        }

        try {
            $result = $action->execute(teamId: (string) auth()->user()->current_team_id);

            session()->flash('boruna_enable_message', $result['message']);
            $this->dispatch('boruna-enabled');
        } catch (\RuntimeException $e) {
            $this->addError('borunaScript', $e->getMessage());
        }
    }

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $allowedTypes = 'llm,connector,rule,hybrid,guardrail,multi_model_consensus,code_execution,gpu_compute,runpod_endpoint,runpod_pod,boruna_script,supabase_edge_function';
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

    public function addConsensusModel(): void
    {
        $this->consensusModels[] = ['provider' => '', 'model' => ''];
    }

    public function removeConsensusModel(int $index): void
    {
        unset($this->consensusModels[$index]);
        $this->consensusModels = array_values($this->consensusModels);
    }

    public function save(): void
    {
        $allowedTypes = 'llm,connector,rule,hybrid,guardrail,multi_model_consensus,code_execution,gpu_compute,runpod_endpoint,runpod_pod,boruna_script,supabase_edge_function';
        if (config('browser.enabled', false)) {
            $allowedTypes .= ',browser';
        }

        $this->validate([
            'name' => 'required|min:2|max:255',
            'description' => 'max:1000',
            'type' => "required|in:{$allowedTypes}",
            'riskLevel' => 'required|in:low,medium,high,critical',
        ]);

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
        } elseif ($this->type === 'runpod_endpoint') {
            $configuration = array_filter([
                'endpoint_id' => $this->runpodEndpointId ?: null,
                'route_path' => $this->runpodRoutePath ?: '/run',
                'use_sync' => $this->runpodUseSync,
                'timeout_seconds' => $this->runpodTimeout,
            ], fn ($v) => $v !== null);
        } elseif ($this->type === 'runpod_pod') {
            $configuration = array_filter([
                'docker_image' => $this->runpodDockerImage ?: null,
                'gpu_type' => $this->runpodGpuType ?: null,
                'gpu_count' => $this->runpodGpuCount,
                'container_disk_gb' => $this->runpodContainerDiskGb,
                'estimated_minutes' => $this->runpodEstimatedMinutes,
            ], fn ($v) => $v !== null);
        } elseif ($this->type === 'boruna_script') {
            $configuration = array_filter([
                'script' => $this->borunaScript ?: null,
                'timeout_seconds' => $this->borunaScriptTimeout,
            ], fn ($v) => $v !== null);
        } elseif ($this->type === 'supabase_edge_function') {
            $configuration = array_filter([
                'project_url' => $this->supabaseProjectUrl ?: null,
                'function_name' => $this->supabaseFunctionName ?: null,
                'anon_key' => $this->supabaseAnonKey ?: null,
            ], fn ($v) => $v !== null);
        } elseif ($this->type === 'multi_model_consensus') {
            $configuration = array_filter([
                'models' => $this->consensusModels ?: null,
                'consensus_threshold' => $this->consensusThreshold,
                'aggregation' => $this->consensusAggregation,
                'system_prompt' => $this->systemPrompt ?: null,
                'prompt_template' => $this->promptTemplate ?: null,
                'max_tokens' => $this->maxTokens,
                'temperature' => $this->temperature,
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

        $borunaPlatform = app(BorunaPlatformService::class);
        $borunaStatus = $borunaPlatform->statusForTeam((string) auth()->user()->current_team_id);

        return view('livewire.skills.create-skill-form', [
            'types' => SkillType::cases(),
            'riskLevels' => RiskLevel::cases(),
            'providers' => $providers,
            'computeProviders' => config('compute_providers.providers', []),
            'canCreate' => true,
            'browserSkillEnabled' => config('browser.enabled', false),
            'borunaStatus' => $borunaStatus,
            'canManageTeam' => Gate::allows('manage-team', $team),
        ])->layout('layouts.app', ['header' => 'Create Skill']);
    }
}
