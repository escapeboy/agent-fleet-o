<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailTemplateResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'             => $this->id,
            'email_theme_id' => $this->email_theme_id,
            'name'           => $this->name,
            'subject'        => $this->subject,
            'preview_text'   => $this->preview_text,
            'design_json'    => $this->design_json,
            'html_cache'     => $this->html_cache,
            'status'         => $this->status->value,
            'visibility'     => $this->visibility->value,
            'created_at'     => $this->created_at->toISOString(),
            'updated_at'     => $this->updated_at->toISOString(),
        ];
    }
}
