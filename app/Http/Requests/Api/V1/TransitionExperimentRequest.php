<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Experiment\Enums\ExperimentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransitionExperimentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(ExperimentStatus::class)],
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
