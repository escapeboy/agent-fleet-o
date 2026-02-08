<?php

namespace App\Domain\Workflow\Actions;

use App\Domain\Workflow\Enums\WorkflowStatus;
use App\Domain\Workflow\Models\Workflow;
use App\Domain\Workflow\Services\GraphValidator;

class ValidateWorkflowGraphAction
{
    public function __construct(
        private readonly GraphValidator $validator,
    ) {}

    /**
     * Validate the workflow graph and optionally activate it if valid.
     *
     * @return array{valid: bool, errors: array, activated: bool}
     */
    public function execute(Workflow $workflow, bool $activateIfValid = false): array
    {
        $errors = $this->validator->validate($workflow);

        $result = [
            'valid' => empty($errors),
            'errors' => $errors,
            'activated' => false,
        ];

        if (empty($errors) && $activateIfValid && $workflow->isDraft()) {
            $workflow->update(['status' => WorkflowStatus::Active]);
            $result['activated'] = true;

            activity()
                ->performedOn($workflow)
                ->withProperties(['version' => $workflow->version])
                ->log('workflow.activated');
        }

        return $result;
    }
}
