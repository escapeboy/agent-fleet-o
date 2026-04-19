<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Agent\Enums\AgentEnvironment;
use App\Domain\Agent\Models\Agent;
use App\Infrastructure\AI\Enums\ReasoningEffort;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $teamId = $this->user()?->current_team_id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'provider' => ['required', 'string', 'max:50'],
            'model' => ['required', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'goal' => ['sometimes', 'nullable', 'string'],
            'backstory' => ['sometimes', 'nullable', 'string'],
            'capabilities' => ['sometimes', 'array'],
            'config' => ['sometimes', 'array'],
            'config.callable_agent_ids' => ['sometimes', 'array', 'max:10'],
            'config.callable_agent_ids.*' => ['uuid', Rule::exists('agents', 'id')->where('team_id', $teamId)],
            'constraints' => ['sometimes', 'array'],
            'budget_cap_credits' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'skill_ids' => ['sometimes', 'array'],
            'skill_ids.*' => ['uuid', Rule::exists('skills', 'id')->where('team_id', $teamId)],
            'tool_profile' => ['nullable', 'string', Rule::in(array_keys(config('tool_profiles.profiles', [])))],
            'environment' => ['sometimes', 'nullable', Rule::enum(AgentEnvironment::class)],
            'config.reasoning_effort' => ['sometimes', 'nullable', Rule::enum(ReasoningEffort::class)],
            'config.use_tool_search' => ['sometimes', 'nullable', 'boolean'],
            'config.tool_search_top_k' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:20'],
            'knowledge_base_id' => ['nullable', 'uuid', Rule::exists('knowledge_bases', 'id')->where('team_id', $teamId)],
            'evaluation_enabled' => ['nullable', 'boolean'],
            'evaluation_sample_rate' => ['nullable', 'numeric', 'min:0', 'max:1'],
            'heartbeat_definition' => ['nullable', 'array'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $callableIds = $this->input('config.callable_agent_ids', []);
            if (empty($callableIds)) {
                return;
            }

            // Shallow cycle detection: check if any referenced agent already references
            // one of our callable agents, creating a potential A→B→A loop
            $referencedAgents = Agent::whereIn('id', $callableIds)->get(['id', 'config']);
            foreach ($referencedAgents as $referenced) {
                $theirCallables = $referenced->config['callable_agent_ids'] ?? [];
                $overlap = array_intersect($theirCallables, $callableIds);
                if (! empty($overlap)) {
                    $validator->errors()->add(
                        'config.callable_agent_ids',
                        "Circular agent reference detected: agent {$referenced->id} already calls one of the specified agents.",
                    );
                    break;
                }
            }
        });
    }
}
