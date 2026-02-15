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
        $fields = $schema['fields'] ?? [];

        foreach ($fields as $field) {
            $key = $field['name'] ?? '';
            if (! $key) {
                continue;
            }

            $this->formData[$key] = $field['default'] ?? match ($field['type'] ?? 'text') {
                'checkbox' => false,
                'number' => null,
                default => '',
            };
        }
    }

    private function buildValidationRules(): array
    {
        $rules = [];
        $schema = $this->task->form_schema ?? [];
        $fields = $schema['fields'] ?? [];

        foreach ($fields as $field) {
            $key = $field['name'] ?? '';
            if (! $key) {
                continue;
            }

            $fieldRules = [];

            if ($field['required'] ?? false) {
                $fieldRules[] = 'required';
            } else {
                $fieldRules[] = 'nullable';
            }

            match ($field['type'] ?? 'text') {
                'number' => $fieldRules[] = 'numeric',
                'email' => $fieldRules[] = 'email',
                'url' => $fieldRules[] = 'url',
                'select' => $fieldRules[] = 'in:'.implode(',', array_column($field['options'] ?? [], 'value')),
                'checkbox' => $fieldRules[] = 'boolean',
                default => $fieldRules[] = 'string',
            };

            if (isset($field['max'])) {
                $fieldRules[] = 'max:'.$field['max'];
            }

            $rules["formData.{$key}"] = $fieldRules;
        }

        return $rules;
    }
}
