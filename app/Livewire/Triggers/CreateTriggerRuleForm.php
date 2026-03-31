<?php

namespace App\Livewire\Triggers;

use App\Domain\Project\Models\Project;
use App\Domain\Trigger\Actions\CreateTriggerRuleAction;
use Livewire\Component;

class CreateTriggerRuleForm extends Component
{
    public string $name = '';

    public string $source_type = '*';

    public ?string $project_id = null;

    /** @var list<array{field: string, operator: string, value: string}> */
    public array $conditionRows = [];

    /** @var list<array{target: string, source: string}> */
    public array $mappingRows = [];

    public int $cooldown_seconds = 0;

    public int $max_concurrent = 1;

    /** @var list<string> */
    public array $availableSourceTypes = [
        '*', 'email', 'rss', 'api_polling', 'calendar', 'github_issues',
        'jira', 'linear', 'sentry', 'datadog', 'pagerduty', 'telegram', 'webhook',
    ];

    /** @var list<string> */
    public array $availableOperators = ['eq', 'neq', 'gte', 'lte', 'contains', 'not_contains', 'exists'];

    public function addConditionRow(): void
    {
        $this->conditionRows[] = ['field' => '', 'operator' => 'eq', 'value' => ''];
    }

    public function removeConditionRow(int $index): void
    {
        unset($this->conditionRows[$index]);
        $this->conditionRows = array_values($this->conditionRows);
    }

    public function addMappingRow(): void
    {
        $this->mappingRows[] = ['target' => '', 'source' => ''];
    }

    public function removeMappingRow(int $index): void
    {
        unset($this->mappingRows[$index]);
        $this->mappingRows = array_values($this->mappingRows);
    }

    public function save(CreateTriggerRuleAction $action): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'source_type' => 'required|string',
            'project_id' => 'nullable|uuid|exists:projects,id',
            'cooldown_seconds' => 'integer|min:0|max:86400',
            'max_concurrent' => 'integer|min:-1|max:10',
            'conditionRows.*.field' => 'required_with:conditionRows.*.operator|string|regex:/^[a-zA-Z0-9_.]+$/',
            'conditionRows.*.operator' => 'required_with:conditionRows.*.field|string|in:eq,neq,gte,lte,contains,not_contains,exists',
            'mappingRows.*.target' => 'required_with:mappingRows.*.source|string',
            'mappingRows.*.source' => 'required_with:mappingRows.*.target|string',
        ]);

        // Build conditions array
        $conditions = null;
        if (! empty($this->conditionRows)) {
            $conditions = [];
            foreach ($this->conditionRows as $row) {
                if ($row['field']) {
                    $conditions[$row['field']] = [$row['operator'] => $row['value']];
                }
            }
        }

        // Build input_mapping array
        $inputMapping = null;
        if (! empty($this->mappingRows)) {
            $inputMapping = [];
            foreach ($this->mappingRows as $row) {
                if ($row['target'] && $row['source']) {
                    $inputMapping[$row['target']] = $row['source'];
                }
            }
        }

        $teamId = auth()->user()->current_team_id;

        $action->execute(
            teamId: $teamId,
            name: $this->name,
            sourceType: $this->source_type,
            projectId: $this->project_id ?: null,
            conditions: $conditions,
            inputMapping: $inputMapping,
            cooldownSeconds: $this->cooldown_seconds,
            maxConcurrent: $this->max_concurrent,
        );

        $this->redirect(route('triggers.index'), navigate: true);
    }

    public function render()
    {
        return view('livewire.triggers.create-trigger-rule-form', [
            'projects' => Project::orderBy('title')->get(['id', 'title']),
        ])->layout('layouts.app', ['header' => 'Create Trigger Rule']);
    }
}
