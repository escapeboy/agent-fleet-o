<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class StoreToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', new Enum(ToolType::class)],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'transport_config' => ['required', 'array'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'tool_definitions' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'risk_level' => ['sometimes', 'nullable', new Enum(ToolRiskLevel::class)],
            'credential_id' => ['sometimes', 'nullable', 'uuid',
                Rule::exists('credentials', 'id')->where('team_id', $this->user()?->current_team_id)],
        ];
    }
}
