<?php

namespace App\Mcp\Tools\Crew;

use App\Domain\Crew\Actions\UpdateCrewAction;
use App\Domain\Crew\Enums\CrewMemberRole;
use App\Domain\Crew\Models\Crew;
use App\Domain\Crew\Models\CrewMember;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

/**
 * MCP tool for updating per-member permission policy on a crew member.
 *
 * Stores tool_allowlist, max_steps, and max_credits in the CrewMember.config JSONB column.
 * Stores context_scope in the CrewMember.context_scope JSONB column.
 * These constraints are enforced at execution time by ExecuteCrewTaskJob / ExecuteAgentAction.
 */
class CrewMemberUpdatePolicyTool extends Tool
{
    protected string $name = 'crew_member_update_policy';

    protected string $description = 'Update the permission policy for a specific crew member. '
        .'Controls which tools the member can use (tool_allowlist), the maximum number of LLM tool-call steps (max_steps), '
        .'and the maximum credits the member may spend per execution (max_credits). '
        .'Can also change the member role (worker, process_reviewer, output_reviewer) and set context_scope (allowed context keys). '
        .'Pass an empty string or null to remove a constraint and restore the agent default.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'crew_id' => $schema->string()
                ->description('The crew UUID')
                ->required(),
            'agent_id' => $schema->string()
                ->description('The agent UUID of the worker whose policy to update')
                ->required(),
            'tool_allowlist' => $schema->string()
                ->description('Comma-separated list of tool names this member is allowed to use. '
                    .'Pass an empty string to remove the restriction (allow all tools).'),
            'max_steps' => $schema->integer()
                ->description('Maximum number of LLM tool-call steps per execution. '
                    .'Pass 0 or omit to use the agent\'s default tier configuration.'),
            'max_credits' => $schema->integer()
                ->description('Maximum credits this member may spend per execution. '
                    .'Pass 0 or omit to remove the per-member credit cap.'),
            'role' => $schema->string()
                ->description('Member role: worker, process_reviewer, output_reviewer. '
                    .'process_reviewer monitors inter-agent collaboration quality; '
                    .'output_reviewer reviews the final synthesized result before it is returned.')
                ->enum(['worker', 'process_reviewer', 'output_reviewer']),
            'context_scope' => $schema->array()
                ->description('Allowlisted top-level context keys this member may see. '
                    .'Null or omit for unrestricted access (full context). '
                    .'Example: ["dependency_outputs", "goal"] restricts context to only those keys.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $validated = $request->validate([
            'crew_id' => 'required|string',
            'agent_id' => 'required|string',
            'tool_allowlist' => 'nullable|string',
            'max_steps' => 'nullable|integer|min:0|max:100',
            'max_credits' => 'nullable|integer|min:0',
            'role' => 'nullable|string|in:worker,process_reviewer,output_reviewer',
            'context_scope' => 'nullable|array',
            'context_scope.*' => 'string',
        ]);

        $teamId = app('mcp.team_id') ?? auth()->user()?->current_team_id;
        if (! $teamId) {
            return Response::error('No current team.');
        }

        $crew = Crew::withoutGlobalScopes()->where('team_id', $teamId)->find($validated['crew_id']);
        if (! $crew) {
            return Response::error('Crew not found.');
        }

        $member = CrewMember::where('crew_id', $crew->id)
            ->where('agent_id', $validated['agent_id'])
            ->first();

        if (! $member) {
            return Response::error(
                "Agent {$validated['agent_id']} is not a worker of crew {$validated['crew_id']}.",
            );
        }

        // Build policy array; pass empty string/0 to clear a constraint
        $policy = [];
        if (array_key_exists('tool_allowlist', $validated)) {
            $policy['tool_allowlist'] = $validated['tool_allowlist'] ?? '';
        }
        if (array_key_exists('max_steps', $validated)) {
            $policy['max_steps'] = ($validated['max_steps'] === 0 || $validated['max_steps'] === null) ? '' : $validated['max_steps'];
        }
        if (array_key_exists('max_credits', $validated)) {
            $policy['max_credits'] = ($validated['max_credits'] === 0 || $validated['max_credits'] === null) ? '' : $validated['max_credits'];
        }

        $updated = app(UpdateCrewAction::class)->updateMemberPolicy($member, $policy);

        // Update role if provided
        if (! empty($validated['role'])) {
            $updated->update(['role' => CrewMemberRole::from($validated['role'])]);
        }

        // Update context_scope if explicitly provided
        if (array_key_exists('context_scope', $validated)) {
            $updated->update(['context_scope' => $validated['context_scope'] ?: null]);
        }

        $updated->refresh();

        return Response::text(json_encode([
            'success' => true,
            'member_id' => $updated->id,
            'agent_id' => $updated->agent_id,
            'crew_id' => $updated->crew_id,
            'role' => $updated->role->value,
            'tool_allowlist' => $updated->tool_allowlist,
            'max_steps' => $updated->max_steps,
            'max_credits' => $updated->max_credits,
            'context_scope' => $updated->context_scope,
        ]));
    }
}
