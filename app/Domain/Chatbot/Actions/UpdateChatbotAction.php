<?php

namespace App\Domain\Chatbot\Actions;

use App\Domain\Chatbot\Models\Chatbot;

class UpdateChatbotAction
{
    public function execute(
        Chatbot $chatbot,
        ?string $name = null,
        ?string $description = null,
        ?string $welcomeMessage = null,
        ?string $fallbackMessage = null,
        ?float $confidenceThreshold = null,
        ?bool $humanEscalationEnabled = null,
        ?array $config = null,
        ?array $widgetConfig = null,
        ?string $workflowId = null,
        ?int $approvalTimeoutHours = null,
    ): Chatbot {
        $data = array_filter([
            'name' => $name,
            'description' => $description,
            'welcome_message' => $welcomeMessage,
            'fallback_message' => $fallbackMessage,
            'confidence_threshold' => $confidenceThreshold,
            'human_escalation_enabled' => $humanEscalationEnabled,
            'workflow_id' => $workflowId,
            'approval_timeout_hours' => $approvalTimeoutHours,
        ], fn ($v) => $v !== null);

        if ($config !== null) {
            $data['config'] = array_merge($chatbot->config ?? [], $config);
        }

        if ($widgetConfig !== null) {
            $data['widget_config'] = array_merge($chatbot->widget_config ?? [], $widgetConfig);
        }

        $chatbot->update($data);

        return $chatbot->fresh();
    }
}
