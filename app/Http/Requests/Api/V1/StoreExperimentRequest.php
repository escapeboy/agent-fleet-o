<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Experiment\Enums\ExperimentTrack;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'thesis' => ['required', 'string'],
            'track' => ['required', Rule::enum(ExperimentTrack::class)],
            'budget_cap_credits' => ['sometimes', 'integer', 'min:0'],
            'max_iterations' => ['sometimes', 'integer', 'min:1'],
            'max_outbound_count' => ['sometimes', 'integer', 'min:0'],
            'constraints' => ['sometimes', 'array'],
            'success_criteria' => ['sometimes', 'array'],
            'workflow_id' => ['sometimes', 'nullable', 'uuid', 'exists:workflows,id'],
        ];
    }
}
