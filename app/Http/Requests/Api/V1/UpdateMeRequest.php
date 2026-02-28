<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class UpdateMeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:users,email,'.$this->user()->id],
            'password' => ['sometimes', Password::defaults()],
        ];

        // Require current_password when changing password
        if ($this->filled('password')) {
            $rules['current_password'] = ['required', 'current_password'];
        }

        return $rules;
    }
}
