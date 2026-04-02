<?php

namespace App\Domain\Agent\Services;

use App\Domain\Agent\Enums\AgentHookPosition;
use App\Domain\Agent\Enums\AgentHookType;
use App\Domain\Agent\Models\Agent;
use App\Domain\Agent\Models\AgentHook;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Executes user-configured hooks at agent lifecycle positions.
 *
 * Hooks are resolved by position: class-level (agent_id=null) first, then
 * instance-level, both sorted by priority (ascending = runs first).
 *
 * Each hook type processes the context differently:
 *   - PromptInjection: appends text to system_prompt or user messages
 *   - OutputTransform: modifies the output text
 *   - Guardrail: validates and can cancel (sets cancel=true in context)
 *   - Notification: fires webhook/notification (async, doesn't block)
 *   - ContextEnrichment: fetches and merges additional context
 */
class AgentHookExecutor
{
    /**
     * Execute all enabled hooks for a given position.
     *
     * @param  array  $context  Mutable execution context. Keys vary by position:
     *                          pre_execute: input, system_prompt, agent
     *                          post_execute: output, execution, agent
     *                          on_tool_call: tool_name, tool_input, agent
     *                          on_error: error, agent
     * @return array The (potentially modified) context
     */
    public function run(AgentHookPosition $position, Agent $agent, array $context): array
    {
        $hooks = $this->resolveHooks($position, $agent);

        if ($hooks->isEmpty()) {
            return $context;
        }

        foreach ($hooks as $hook) {
            try {
                $context = $this->executeHook($hook, $context);

                // Guardrail cancellation — stop processing further hooks
                if (! empty($context['cancel'])) {
                    Log::info('AgentHook: guardrail cancelled execution', [
                        'hook' => $hook->name,
                        'agent_id' => $agent->id,
                        'reason' => $context['cancel_reason'] ?? 'Guardrail triggered',
                    ]);
                    break;
                }
            } catch (\Throwable $e) {
                Log::warning('AgentHook: hook execution failed', [
                    'hook_id' => $hook->id,
                    'hook_name' => $hook->name,
                    'error' => $e->getMessage(),
                ]);
                // Hook failure should not block agent execution
            }
        }

        return $context;
    }

    /**
     * Resolve hooks for a position: class-level (team-wide) + instance-level (agent-specific).
     *
     * @return Collection<int, AgentHook>
     */
    private function resolveHooks(AgentHookPosition $position, Agent $agent): Collection
    {
        return AgentHook::where('team_id', $agent->team_id)
            ->where('position', $position)
            ->where('enabled', true)
            ->where(function ($q) use ($agent) {
                $q->whereNull('agent_id')           // class-level
                    ->orWhere('agent_id', $agent->id); // instance-level
            })
            ->orderByRaw('agent_id IS NOT NULL')  // class-level first
            ->orderBy('priority')
            ->get();
    }

    private function executeHook(AgentHook $hook, array $context): array
    {
        return match ($hook->type) {
            AgentHookType::PromptInjection => $this->executePromptInjection($hook, $context),
            AgentHookType::OutputTransform => $this->executeOutputTransform($hook, $context),
            AgentHookType::Guardrail => $this->executeGuardrail($hook, $context),
            AgentHookType::Notification => $this->executeNotification($hook, $context),
            AgentHookType::ContextEnrichment => $this->executeContextEnrichment($hook, $context),
        };
    }

    private function executePromptInjection(AgentHook $hook, array $context): array
    {
        $text = $hook->config['text'] ?? '';
        $target = $hook->config['target'] ?? 'system_prompt';

        if ($text && isset($context[$target])) {
            $context[$target] .= "\n\n".$text;
        } elseif ($text && $target === 'system_prompt') {
            $context['system_prompt'] = ($context['system_prompt'] ?? '')."\n\n".$text;
        }

        return $context;
    }

    private function executeOutputTransform(AgentHook $hook, array $context): array
    {
        $transform = $hook->config['transform'] ?? null;

        if ($transform === 'prefix' && isset($context['output'])) {
            $context['output'] = ($hook->config['prefix'] ?? '').$context['output'];
        } elseif ($transform === 'suffix' && isset($context['output'])) {
            $context['output'] .= $hook->config['suffix'] ?? '';
        } elseif ($transform === 'replace' && isset($context['output'])) {
            $search = $hook->config['search'] ?? '';
            $replace = $hook->config['replace'] ?? '';
            if ($search) {
                $context['output'] = str_replace($search, $replace, $context['output']);
            }
        }

        return $context;
    }

    private function executeGuardrail(AgentHook $hook, array $context): array
    {
        $rules = $hook->config['rules'] ?? [];

        foreach ($rules as $rule) {
            $field = $rule['field'] ?? 'input';
            $operator = $rule['operator'] ?? 'contains';
            $value = $rule['value'] ?? '';
            $fieldValue = $context[$field] ?? '';

            if (is_array($fieldValue)) {
                $fieldValue = json_encode($fieldValue);
            }

            $matched = match ($operator) {
                'contains' => str_contains((string) $fieldValue, $value),
                'not_contains' => ! str_contains((string) $fieldValue, $value),
                'regex' => (bool) preg_match($value, (string) $fieldValue),
                'max_length' => mb_strlen((string) $fieldValue) > (int) $value,
                default => false,
            };

            if ($matched) {
                $context['cancel'] = true;
                $context['cancel_reason'] = $rule['message'] ?? "Guardrail rule '{$operator}' matched on '{$field}'";

                return $context;
            }
        }

        return $context;
    }

    private function executeNotification(AgentHook $hook, array $context): array
    {
        // Dispatch notification asynchronously — don't block execution
        $channel = $hook->config['channel'] ?? 'log';
        $message = $hook->config['message'] ?? 'Agent hook triggered';

        Log::info('AgentHook: notification', [
            'hook' => $hook->name,
            'channel' => $channel,
            'message' => $message,
            'context_keys' => array_keys($context),
        ]);

        return $context;
    }

    private function executeContextEnrichment(AgentHook $hook, array $context): array
    {
        $source = $hook->config['source'] ?? null;
        $target = $hook->config['target'] ?? 'system_prompt';

        if ($source === 'static' && isset($hook->config['content'])) {
            $context[$target] = ($context[$target] ?? '')."\n\n".$hook->config['content'];
        }

        return $context;
    }
}
