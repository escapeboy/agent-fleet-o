<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatbotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'agent_is_dedicated' => $this->agent_is_dedicated,
            'agent_id' => $this->agent_id,
            'config' => $this->config,
            'widget_config' => $this->widget_config,
            'confidence_threshold' => (float) $this->confidence_threshold,
            'human_escalation_enabled' => $this->human_escalation_enabled,
            'welcome_message' => $this->welcome_message,
            'fallback_message' => $this->fallback_message,
            'active_tokens_count' => $this->whenCounted('activeTokens'),
            'sessions_count' => $this->whenCounted('sessions'),
            'created_at' => $this->created_at->toISOString(),
            'updated_at' => $this->updated_at->toISOString(),
        ];
    }
}
