<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Credential\Enums\CredentialSource;
use App\Domain\Credential\Enums\CredentialType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class StoreCredentialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'credential_type' => ['required', new Enum(CredentialType::class)],
            'secret_data' => ['required', 'array'],
            'description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'metadata' => ['sometimes', 'nullable', 'array'],
            'expires_at' => ['sometimes', 'nullable', 'date'],
            'creator_source' => ['sometimes', new Enum(CredentialSource::class)],
            'agent_id' => ['sometimes', 'nullable', 'uuid'],
        ];
    }
}
