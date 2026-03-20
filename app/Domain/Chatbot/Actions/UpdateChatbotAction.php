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
        ?string $provider = null,
        ?string $model = null,
        ?string $systemPrompt = null,
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

        if ($chatbot->agent_is_dedicated && $chatbot->agent && ($provider !== null || $model !== null || $systemPrompt !== null)) {
            $agentUpdates = [];

            if ($provider !== null || $model !== null) {
                $p = $provider ?? $chatbot->agent->provider;
                $m = $model ?? $chatbot->agent->model;
                $pricing = config("llm_pricing.providers.{$p}.{$m}", ['input' => 0, 'output' => 0]);
                $agentUpdates['provider'] = $p;
                $agentUpdates['model'] = $m;
                $agentUpdates['cost_per_1k_input'] = $pricing['input'] ?? 0;
                $agentUpdates['cost_per_1k_output'] = $pricing['output'] ?? 0;
            }

            if ($systemPrompt !== null) {
                $agentUpdates['backstory'] = $systemPrompt;
            }

            $chatbot->agent->update($agentUpdates);
        }

        return $chatbot->fresh(['agent']);
    }
}
