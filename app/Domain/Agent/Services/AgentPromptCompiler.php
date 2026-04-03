<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Models\Agent;

class AgentPromptCompiler
{
    public function compile(Agent $agent, array $runtimeContext = []): string
    {
        $template = $agent->system_prompt_template;

        if (empty($template)) {
            return $agent->backstory ?? '';
        }

        $sections = [];

        if (! empty($template['personality'])) {
            $sections[] = "## Personality\n".$template['personality'];
        }

        if (! empty($template['rules'])) {
            $rules = is_array($template['rules'])
                ? implode("\n", array_map(fn ($r) => "- {$r}", $template['rules']))
                : $template['rules'];
            $sections[] = "## Rules\n".$rules;
        }

        if (! empty($template['context_injection'])) {
            $sections[] = "## Context\n".$template['context_injection'];
        }

        if (! empty($template['output_format'])) {
            $sections[] = "## Output Format\n".$template['output_format'];
        }

        $compiled = implode("\n\n", $sections);

        $variables = $this->buildVariables($agent, $runtimeContext);
        foreach ($variables as $key => $value) {
            $compiled = str_replace('{{'.$key.'}}', $value, $compiled);
        }

        return $compiled;
    }

    private function buildVariables(Agent $agent, array $context): array
    {
        $vars = [
            'agent.name' => $agent->name,
            'agent.role' => $agent->role ?? '',
            'agent.goal' => $agent->goal ?? '',
            'current_date' => now()->toDateString(),
            'current_datetime' => now()->toDateTimeString(),
        ];

        if (isset($context['recent_memories'])) {
            $vars['recent_memories'] = is_string($context['recent_memories'])
                ? $context['recent_memories']
                : json_encode($context['recent_memories'], JSON_PRETTY_PRINT);
        } else {
            $vars['recent_memories'] = 'No recent memories available.';
        }

        if (isset($context['available_tools'])) {
            $vars['available_tools'] = is_string($context['available_tools'])
                ? $context['available_tools']
                : implode(', ', $context['available_tools']);
        } else {
            $vars['available_tools'] = 'Standard tools.';
        }

        return $vars;
    }
}
