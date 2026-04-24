<?php

namespace App\Domain\Assistant\Services;

use App\Domain\Agent\Models\Agent;
use App\Domain\Crew\Models\Crew;
use App\Domain\Experiment\Models\Experiment;
use App\Domain\Project\Models\Project;
use App\Domain\Workflow\Models\Workflow;

class ContextResolver
{
    public function resolve(?string $type, ?string $id): string
    {
        if (! $type || ! $id) {
            return 'The user is on the dashboard or a list page.';
        }

        return match ($type) {
            'experiment' => $this->experimentContext($id),
            'project' => $this->projectContext($id),
            'agent' => $this->agentContext($id),
            'crew' => $this->crewContext($id),
            'workflow' => $this->workflowContext($id),
            'selection' => $this->selectionContext($id),
            default => "The user is viewing a {$type} page.",
        };
    }

    /**
     * `$id` is a JSON-encoded `{kind: string, ids: string[]}` payload written by
     * AssistantPanel when an index page hands a multi-select bundle to the chat.
     * Agents can then call list/get/mutation MCP tools for the selected IDs.
     */
    private function selectionContext(string $id): string
    {
        $decoded = json_decode($id, true);
        if (! is_array($decoded)) {
            return 'The user has selected items on a list page.';
        }

        $kind = (string) ($decoded['kind'] ?? 'item');
        $ids = array_values(array_filter((array) ($decoded['ids'] ?? []), 'is_string'));
        $ids = array_slice($ids, 0, 50);
        $count = count($ids);
        if ($count === 0) {
            return 'The user cleared their selection.';
        }

        $list = implode(', ', array_map(fn ($i) => "`{$i}`", $ids));

        return sprintf(
            "The user has multi-selected %d %s record%s on a list page. IDs: %s. When asked to 'do X to these', iterate over the list and call the appropriate MCP tool per ID. Be concise in your summary.",
            $count,
            $kind,
            $count === 1 ? '' : 's',
            $list,
        );
    }

    private function experimentContext(string $id): string
    {
        $exp = Experiment::with('stages')->find($id);
        if (! $exp) {
            return '';
        }

        $parts = [
            "The user is viewing Experiment '{$exp->title}' (ID: {$exp->id}).",
            "Status: {$exp->status->value}.",
            "Track: {$exp->track->value}.",
            "Budget: {$exp->budget_spent_credits}/{$exp->budget_cap_credits} credits.",
            "Created: {$exp->created_at->diffForHumans()}.",
        ];

        if ($exp->stages->isNotEmpty()) {
            $parts[] = 'Stages: '.$exp->stages->pluck('type')->implode(', ').'.';
        }

        return implode(' ', $parts);
    }

    private function projectContext(string $id): string
    {
        $project = Project::find($id);
        if (! $project) {
            return '';
        }

        $parts = [
            "The user is viewing Project '{$project->title}' (ID: {$project->id}).",
            "Type: {$project->type->value}.",
            "Status: {$project->status->value}.",
            "Created: {$project->created_at->diffForHumans()}.",
        ];

        return implode(' ', $parts);
    }

    private function agentContext(string $id): string
    {
        $agent = Agent::find($id);
        if (! $agent) {
            return '';
        }

        $parts = [
            "The user is viewing Agent '{$agent->name}' (ID: {$agent->id}).",
            "Role: {$agent->role}.",
            "Status: {$agent->status->value}.",
            "Provider: {$agent->provider}/{$agent->model}.",
        ];

        return implode(' ', $parts);
    }

    private function crewContext(string $id): string
    {
        $crew = Crew::with('members')->find($id);
        if (! $crew) {
            return '';
        }

        $parts = [
            "The user is viewing Crew '{$crew->name}' (ID: {$crew->id}).",
            "Status: {$crew->status->value}.",
            "Members: {$crew->members->count()}.",
        ];

        return implode(' ', $parts);
    }

    private function workflowContext(string $id): string
    {
        $workflow = Workflow::with('nodes')->find($id);
        if (! $workflow) {
            return '';
        }

        $parts = [
            "The user is viewing Workflow '{$workflow->name}' (ID: {$workflow->id}).",
            "Status: {$workflow->status->value}.",
            "Nodes: {$workflow->nodes->count()}.",
        ];

        return implode(' ', $parts);
    }
}
