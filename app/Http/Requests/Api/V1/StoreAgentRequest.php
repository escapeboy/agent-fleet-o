<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;

class StoreAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'goal' => ['sometimes', 'nullable', 'string'],
            'backstory' => ['sometimes', 'nullable', 'string'],
            'capabilities' => ['sometimes', 'array'],
            'config' => ['sometimes', 'array'],
            'constraints' => ['sometimes', 'array'],
            'budget_cap_credits' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'skill_ids' => ['sometimes', 'array'],
            'skill_ids.*' => ['uuid', 'exists:skills,id'],
        ];
    }
}
