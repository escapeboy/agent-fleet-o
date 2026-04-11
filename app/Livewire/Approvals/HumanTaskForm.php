<?php

namespace App\Livewire\Approvals;

use App\Domain\Approval\Actions\CompleteHumanTaskAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Models\ApprovalRequest;
use Livewire\Component;

class HumanTaskForm extends Component
{
    public ApprovalRequest $task;

    public array $formData = [];

    public string $reviewerNotes = '';

    public bool $showRejectModal = false;

    public string $rejectionReason = '';

    public function mount(ApprovalRequest $task): void
    {
        $this->task = $task;
        $this->initFormData();
    }

    public function submit(): void
    {
        $this->validate($this->buildValidationRules());

        app(CompleteHumanTaskAction::class)->execute(
            approvalRequest: $this->task,
            formResponse: $this->formData,
            reviewerId: auth()->id(),
            notes: $this->reviewerNotes ?: null,
        );

        session()->flash('message', 'Human task completed successfully.');
        $this->dispatch('human-task-completed');
    }

    public function reject(): void
    {
        if (empty($this->rejectionReason)) {
            $this->addError('rejectionReason', 'A reason is required when rejecting.');

            return;
        }

        app(RejectAction::class)->execute(
            $this->task,
            auth()->id(),
            $this->rejectionReason,
        );

        $this->showRejectModal = false;
        session()->flash('message', 'Human task rejected.');
        $this->dispatch('human-task-completed');
    }

    public function render()
    {
        return view('livewire.approvals.human-task-form');
    }

    private function initFormData(): void
    {
        $schema = $this->task->form_schema ?? [];

        // Support JSON Schema format (properties/required) used by workflow nodes
        if (isset($schema['properties'])) {
            foreach ($schema['properties'] as $key => $def) {
                $this->formData[$key] = match ($def['type'] ?? 'string') {
                    'boolean' => false,
                    'integer', 'number' => null,
                    default => '',
                };
            }

            return;
        }

        // Legacy flat fields format
        foreach ($schema['fields'] ?? [] as $field) {
            $key = $field['name'] ?? '';
            if (! $key) {
                continue;
            }

            $this->formData[$key] = $field['default'] ?? match ($field['type'] ?? 'text') {
                'checkbox', 'boolean' => false,
                'number' => null,
                'multi_select' => [],
                default => '',
            };
        }
    }

    private function buildValidationRules(): array
    {
        $rules = [];
        $schema = $this->task->form_schema ?? [];

        // Support JSON Schema format (properties/required) used by workflow nodes
        if (isset($schema['properties'])) {
            $required = $schema['required'] ?? [];

            foreach ($schema['properties'] as $key => $def) {
                $fieldRules = in_array($key, $required) ? ['required'] : ['nullable'];

                if (isset($def['enum'])) {
                    $fieldRules[] = 'in:'.implode(',', $def['enum']);
                } else {
                    match ($def['type'] ?? 'string') {
                        'integer', 'number' => $fieldRules[] = 'numeric',
                        'boolean' => $fieldRules[] = 'boolean',
                        default => $fieldRules[] = 'string',
                    };
                }

                $rules["formData.{$key}"] = $fieldRules;
            }

            return $rules;
        }

        // Legacy flat fields format
        foreach ($schema['fields'] ?? [] as $field) {
            $key = $field['name'] ?? '';
            if (! $key) {
                continue;
            }

            $fieldRules = [];
            $type = $field['type'] ?? 'text';

            if ($field['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            match ($type) {
                'number' => $fieldRules[] = 'numeric',
                'email' => $fieldRules[] = 'email',
                'url' => $fieldRules[] = 'url',
                'date' => $fieldRules[] = 'date',
                'select', 'radio_cards' => $fieldRules[] = 'in:'.implode(',', array_column($field['options'] ?? [], 'value')),
                'multi_select' => $fieldRules[] = 'array',
                'checkbox', 'boolean' => $fieldRules[] = 'boolean',
                default => $fieldRules[] = 'string',
            };

            if ($type === 'number') {
                if (isset($field['min'])) {
                    $fieldRules[] = 'min:'.$field['min'];
                }
                if (isset($field['max'])) {
                    $fieldRules[] = 'max:'.$field['max'];
                }
            } elseif (isset($field['max'])) {
                $fieldRules[] = 'max:'.$field['max'];
            }

            $rules["formData.{$key}"] = $fieldRules;

            // Per-item validation for multi_select: every value must be in the whitelist.
            if ($type === 'multi_select') {
                $allowed = implode(',', array_column($field['options'] ?? [], 'value'));
                $rules["formData.{$key}.*"] = $allowed ? ['string', "in:{$allowed}"] : ['string'];
            }
        }

        return $rules;
    }
}
