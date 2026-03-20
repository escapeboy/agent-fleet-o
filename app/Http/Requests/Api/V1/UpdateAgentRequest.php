<?php

namespace App\Http\Requests\Api\V1;

use App\Domain\Agent\Models\Agent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAgentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $teamId = $this->user()?->current_team_id;

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'role' => ['sometimes', 'nullable', 'string', 'max:255'],
            'goal' => ['sometimes', 'nullable', 'string'],
            'backstory' => ['sometimes', 'nullable', 'string'],
            'provider' => ['sometimes', 'string', 'max:50'],
            'model' => ['sometimes', 'string', 'max:100'],
            'capabilities' => ['sometimes', 'array'],
            'config' => ['sometimes', 'array'],
            'config.callable_agent_ids' => ['sometimes', 'array', 'max:10'],
            'config.callable_agent_ids.*' => ['uuid', Rule::exists('agents', 'id')->where('team_id', $teamId)],
            'constraints' => ['sometimes', 'array'],
            'budget_cap_credits' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'skill_ids' => ['sometimes', 'array'],
            'skill_ids.*' => ['uuid', Rule::exists('skills', 'id')->where('team_id', $teamId)],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            $callableIds = $this->input('config.callable_agent_ids', []);
            if (empty($callableIds)) {
                return;
            }

            $agentId = $this->route('agent')?->id;

            // Self-reference check
            if (in_array($agentId, $callableIds, true)) {
                $validator->errors()->add('config.callable_agent_ids', 'An agent cannot call itself as a tool.');

                return;
            }

            // Shallow cycle detection: check if any referenced agent already calls this agent
            $referencedAgents = Agent::whereIn('id', $callableIds)->get(['id', 'config']);
            foreach ($referencedAgents as $referenced) {
                $theirCallables = $referenced->config['callable_agent_ids'] ?? [];
                if (in_array($agentId, $theirCallables, true)) {
                    $validator->errors()->add(
                        'config.callable_agent_ids',
                        "Circular agent reference detected: agent {$referenced->id} already calls this agent.",
                    );
                    break;
                }
            }
        });
    }
}
