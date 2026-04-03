<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Models\Memory;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class ExportAgentWorkspaceAction
{
    public function execute(Agent $agent, string $format = 'zip', bool $includeMemories = true): string
    {
        $workspace = $this->buildWorkspace($agent, $includeMemories);

        return match ($format) {
            'yaml' => $this->exportAsYaml($workspace, $agent),
            default => $this->exportAsZip($workspace, $agent),
        };
    }

    private function buildWorkspace(Agent $agent, bool $includeMemories): array
    {
        $workspace = [
            'config' => [
                'version' => '1.0',
                'exported_at' => now()->toISOString(),
                'source' => 'fleetq',
            ],
            'identity' => [
                'name' => $agent->name,
                'slug' => $agent->slug,
                'role' => $agent->role,
                'goal' => $agent->goal,
                'backstory' => $agent->backstory,
                'personality' => $agent->personality,
                'provider' => $agent->provider,
                'model' => $agent->model,
            ],
            'system_prompt_template' => $agent->system_prompt_template,
            'tools' => $agent->tools->map(fn ($tool) => [
                'name' => $tool->name,
                'type' => $tool->type->value,
                'type_value' => $tool->type->value,
                'pivot' => [
                    'priority' => $tool->pivot->priority ?? 0,
                    'overrides' => $tool->pivot->overrides ?? [],
                    'approval_mode' => $tool->pivot->approval_mode ?? 'auto',
                    'approval_timeout_minutes' => $tool->pivot->approval_timeout_minutes ?? 30,
                    'approval_timeout_action' => $tool->pivot->approval_timeout_action ?? 'deny',
                ],
            ])->values()->toArray(),
        ];

        if ($includeMemories) {
            $workspace['memories'] = Memory::withoutGlobalScopes()
                ->where('agent_id', $agent->id)
                ->where('team_id', $agent->team_id)
                ->get(['key', 'content', 'importance', 'confidence', 'tags', 'tier'])
                ->toArray();
        }

        $workspace['soul_md'] = $this->buildSoulMd($agent);

        return $workspace;
    }

    private function buildSoulMd(Agent $agent): string
    {
        $template = $agent->system_prompt_template;
        if (empty($template)) {
            return "# {$agent->name}\n\n".($agent->backstory ?? '');
        }

        $sections = ["# {$agent->name}"];

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

        return implode("\n\n", $sections);
    }

    private function exportAsZip(array $workspace, Agent $agent): string
    {
        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $agent->slug ?? $agent->id);
        $filename = 'agent-workspace-'.$safeSlug.'-'.now()->format('Y-m-d').'.zip';
        $path = storage_path('app/temp/'.$filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('soul.md', $workspace['soul_md']);
        $zip->addFromString('identity.json', json_encode($workspace['identity'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('tools.json', json_encode($workspace['tools'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('config.json', json_encode($workspace['config'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if (! empty($workspace['system_prompt_template'])) {
            $zip->addFromString('system_prompt_template.json', json_encode($workspace['system_prompt_template'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        if (! empty($workspace['memories'])) {
            $zip->addFromString('memories.json', json_encode($workspace['memories'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }

        $zip->close();

        return $path;
    }

    private function exportAsYaml(array $workspace, Agent $agent): string
    {
        $safeSlug = preg_replace('/[^a-zA-Z0-9_-]/', '', $agent->slug ?? $agent->id);
        $filename = 'agent-workspace-'.$safeSlug.'-'.now()->format('Y-m-d').'.yaml';
        $path = storage_path('app/temp/'.$filename);

        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, Yaml::dump($workspace, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK));

        return $path;
    }
}
