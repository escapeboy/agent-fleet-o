<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateToolRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', new Enum(ToolStatus::class)],
            'transport_config' => ['sometimes', 'array'],
            'credentials' => ['sometimes', 'nullable', 'array'],
            'tool_definitions' => ['sometimes', 'nullable', 'array'],
            'settings' => ['sometimes', 'nullable', 'array'],
            'risk_level' => ['sometimes', 'nullable', new Enum(ToolRiskLevel::class)],
            'credential_id' => ['sometimes', 'nullable', 'uuid', 'exists:credentials,id'],
            'clear_credential_id' => ['sometimes', 'boolean'],
        ];
    }
}
