<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Webhook\Enums\WebhookEvent;
use Illuminate\Foundation\Http\FormRequest;

class StoreWebhookEndpointRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $validEvents = implode(',', array_column(WebhookEvent::cases(), 'value'));

        return [
            'name' => ['required', 'string', 'max:255'],
            'url' => ['required', 'url', 'max:2048'],
            'events' => ['required', 'array', 'min:1'],
            'events.*' => ['in:'.$validEvents.',*'],
            'secret' => ['nullable', 'string', 'max:255'],
            'headers' => ['nullable', 'array'],
            'retry_config' => ['nullable', 'array'],
            'retry_config.max_retries' => ['nullable', 'integer', 'min:0', 'max:10'],
        ];
    }
}
