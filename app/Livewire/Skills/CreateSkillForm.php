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

    // Step 3: Configuration
    public string $provider = '';
    public string $model = '';
    public string $systemPrompt = '';
    public string $promptTemplate = '';
    public int $maxTokens = 4096;
    public float $temperature = 0.7;

    public function nextStep(): void
    {
        if ($this->step === 1) {
            $this->validate([
                'name' => 'required|min:2|max:255',
                'description' => 'max:1000',
                'type' => 'required|in:llm,connector,rule,hybrid',
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
        $team = auth()->user()->currentTeam();

        $inputSchema = $this->buildSchema($this->inputFields);
        $outputSchema = $this->buildSchema($this->outputFields);

        $configuration = array_filter([
            'provider' => $this->provider ?: null,
            'model' => $this->model ?: null,
            'max_tokens' => $this->maxTokens,
            'temperature' => $this->temperature,
            'prompt_template' => $this->promptTemplate ?: null,
        ]);

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
        $providers = app(ProviderResolver::class)->availableProviders();

        return view('livewire.skills.create-skill-form', [
            'types' => SkillType::cases(),
            'riskLevels' => RiskLevel::cases(),
            'providers' => $providers,
            'canCreate' => true,
        ])->layout('layouts.app', ['header' => 'Create Skill']);
    }
}
