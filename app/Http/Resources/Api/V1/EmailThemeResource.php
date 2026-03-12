<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailThemeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'status'            => $this->status->value,
            'logo_url'          => $this->logo_url,
            'logo_width'        => $this->logo_width,
            'background_color'  => $this->background_color,
            'canvas_color'      => $this->canvas_color,
            'primary_color'     => $this->primary_color,
            'text_color'        => $this->text_color,
            'heading_color'     => $this->heading_color,
            'muted_color'       => $this->muted_color,
            'divider_color'     => $this->divider_color,
            'font_name'         => $this->font_name,
            'font_url'          => $this->font_url,
            'font_family'       => $this->font_family,
            'heading_font_size' => $this->heading_font_size,
            'body_font_size'    => $this->body_font_size,
            'line_height'       => $this->line_height,
            'email_width'       => $this->email_width,
            'content_padding'   => $this->content_padding,
            'company_name'      => $this->company_name,
            'company_address'   => $this->company_address,
            'footer_text'       => $this->footer_text,
            'is_system_default' => $this->is_system_default,
            'created_at'        => $this->created_at->toISOString(),
            'updated_at'        => $this->updated_at->toISOString(),
        ];
    }
}
