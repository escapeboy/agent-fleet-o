<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'goal' => ['sometimes', 'nullable', 'string'],
            'backstory' => ['sometimes', 'nullable', 'string'],
            'provider' => ['sometimes', 'string', 'max:50'],
            'model' => ['sometimes', 'string', 'max:100'],
            'capabilities' => ['sometimes', 'array'],
            'config' => ['sometimes', 'array'],
            'constraints' => ['sometimes', 'array'],
            'budget_cap_credits' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'skill_ids' => ['sometimes', 'array'],
            'skill_ids.*' => ['uuid', 'exists:skills,id'],
        ];
    }
}
