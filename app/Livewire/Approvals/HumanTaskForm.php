<?php

namespace App\Livewire\Approvals;

use App\Domain\Approval\Actions\CompleteHumanTaskAction;
use App\Domain\Approval\Actions\RejectAction;
use App\Domain\Approval\Models\ApprovalRequest;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class HumanTaskForm extends Component
{
    public ApprovalRequest $task;

    public array $formData = [];

    public string $reviewerNotes = '';

    public bool $showRejectModal = false;

    public string $rejectionReason = '';

    public bool $showEscalationConfig = false;

    public ?int $slaHours = null;

    /** @var array<int, string> Ordered list of assignee user IDs. */
    public array $escalationChain = [];

    public function mount(ApprovalRequest $task): void
    {
        $this->task = $task;
        $this->initFormData();
        $this->slaHours = $task->context['sla_hours'] ?? null;
        $this->escalationChain = array_values(array_filter((array) $task->escalation_chain));
    }

    /**
     * Team members eligible as escalation assignees, scoped to the task's team.
     *
     * @return array<int, array{id: string, name: string}>
     */
    public function getTeamMembersProperty(): array
    {
        return $this->task->team
            ->users()
            ->get(['users.id', 'users.name'])
            ->map(fn ($u) => ['id' => $u->id, 'name' => $u->name])
            ->all();
    }

    public function addEscalationLevel(): void
    {
        Gate::authorize('edit-content');

        $this->escalationChain[] = '';
    }

    public function removeEscalationLevel(int $index): void
    {
        Gate::authorize('edit-content');

        unset($this->escalationChain[$index]);
        $this->escalationChain = array_values($this->escalationChain);
    }

    public function saveEscalationConfig(): void
    {
        Gate::authorize('edit-content');

        $memberIds = array_column($this->teamMembers, 'id');

        $this->validate([
            'slaHours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'escalationChain' => ['array'],
            'escalationChain.*' => ['required', 'string', 'in:'.implode(',', $memberIds)],
        ]);

        $chain = array_values(array_filter($this->escalationChain));

        $context = $this->task->context ?? [];
        if ($this->slaHours !== null) {
            $context['sla_hours'] = $this->slaHours;
        } else {
            unset($context['sla_hours']);
        }

        $this->task->update([
            'context' => $context,
            'escalation_chain' => $chain ?: null,
        ]);

        $this->escalationChain = $chain;
        $this->showEscalationConfig = false;
        session()->flash('message', 'Escalation configuration saved.');
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
        Gate::authorize('edit-content');

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
