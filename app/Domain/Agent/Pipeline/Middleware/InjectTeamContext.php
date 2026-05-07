<?php

namespace App\Domain\Agent\Pipeline\Middleware;

use App\Domain\Agent\Pipeline\AgentExecutionContext;
use App\Domain\Crew\Models\CrewMember;
use Closure;

/**
 * Injects the agent's team graph (crew memberships + peers) into the system prompt
 * so the agent understands its role inside the multi-agent organization it belongs to.
 *
 * Inspired by Offsite (teamoffsite.ai): "agents learn to work together based on how
 * you structure your team." See claudedocs/research_offsite_2026-04-27.md.
 *
 * Reads existing crew_members + crews; no new tables.
 */
class InjectTeamContext
{
    public function handle(AgentExecutionContext $ctx, Closure $next): AgentExecutionContext
    {
        $memberships = CrewMember::query()
            ->with([
                'crew:id,name,description',
                'crew.members' => fn ($q) => $q->select('id', 'crew_id', 'agent_id', 'external_agent_id', 'member_kind', 'role'),
                'crew.members.agent:id,name,role',
            ])
            ->where('agent_id', $ctx->agent->id)
            ->get();

        if ($memberships->isEmpty()) {
            return $next($ctx);
        }

        $sections = [];

        foreach ($memberships as $self) {
            $crew = $self->crew;
            if (! $crew) {
                continue;
            }

            $peerLines = [];
            foreach ($crew->members ?? [] as $peer) {
                if ($peer->id === $self->id) {
                    continue;
                }

                $peerName = $peer->agent->name ?? '(external agent)';
                $peerCrewRole = $peer->role->value ?? 'member';
                $peerPersonaRole = $peer->agent?->role;

                $line = "- {$peerName} — {$peerCrewRole}";
                if ($peerPersonaRole) {
                    $line .= " ({$peerPersonaRole})";
                }
                $peerLines[] = $line;
            }

            if (empty($peerLines)) {
                continue;
            }

            $myCrewRole = $self->role->value ?? 'member';

            $section = "## Team Context: {$crew->name}\n\n";
            $section .= "Your role on this team: **{$myCrewRole}**.\n";
            if (! empty($crew->description)) {
                $section .= "Team goal: {$crew->description}\n";
            }
            $section .= "\nTeammates:\n".implode("\n", $peerLines);

            $sections[] = $section;
        }

        if (! empty($sections)) {
            $ctx->systemPromptParts[] = implode("\n\n", $sections);
        }

        return $next($ctx);
    }
}
