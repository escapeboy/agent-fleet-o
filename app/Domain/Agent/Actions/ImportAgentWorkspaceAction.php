<?php

namespace App\Domain\Agent\Actions;

use App\Domain\Agent\Models\Agent;
use App\Domain\Memory\Models\Memory;
use App\Domain\Tool\Models\Tool;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Yaml\Yaml;
use ZipArchive;

class ImportAgentWorkspaceAction
{
    public function execute(
        UploadedFile $file,
        string $teamId,
        string $mode = 'create',
        ?string $mergeAgentId = null,
    ): array {
        $workspace = $this->parseFile($file);

        return DB::transaction(function () use ($workspace, $teamId, $mode, $mergeAgentId) {
            if ($mode === 'merge' && $mergeAgentId) {
                return $this->mergeIntoAgent($workspace, $mergeAgentId, $teamId);
            }

            return $this->createNewAgent($workspace, $teamId);
        });
    }

    public function executeFromPath(
        string $filePath,
        string $teamId,
        string $mode = 'create',
        ?string $mergeAgentId = null,
    ): array {
        $workspace = $this->parseFromPath($filePath);

        return DB::transaction(function () use ($workspace, $teamId, $mode, $mergeAgentId) {
            if ($mode === 'merge' && $mergeAgentId) {
                return $this->mergeIntoAgent($workspace, $mergeAgentId, $teamId);
            }

            return $this->createNewAgent($workspace, $teamId);
        });
    }

    private function parseFile(UploadedFile $file): array
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if ($extension === 'zip') {
            return $this->parseZip($file->getRealPath());
        }

        if (in_array($extension, ['yaml', 'yml'])) {
            return Yaml::parse($file->getContent());
        }

        throw new \InvalidArgumentException("Unsupported format: {$extension}. Use .zip or .yaml");
    }

    private function parseFromPath(string $filePath): array
    {
        $realPath = realpath($filePath);
        $allowedDir = storage_path('app');

        if (! $realPath || ! str_starts_with($realPath, $allowedDir)) {
            throw new \InvalidArgumentException('File path must be within storage/app directory.');
        }

        $extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

        if ($extension === 'zip') {
            return $this->parseZip($realPath);
        }

        if (in_array($extension, ['yaml', 'yml'])) {
            return Yaml::parse(file_get_contents($realPath));
        }

        throw new \InvalidArgumentException("Unsupported format: {$extension}. Use .zip or .yaml");
    }

    private function parseZip(string $path): array
    {
        $zip = new ZipArchive;
        $zip->open($path);

        $workspace = [];

        if ($zip->locateName('identity.json') !== false) {
            $workspace['identity'] = json_decode($zip->getFromName('identity.json'), true);
        }
        if ($zip->locateName('tools.json') !== false) {
            $workspace['tools'] = json_decode($zip->getFromName('tools.json'), true);
        }
        if ($zip->locateName('config.json') !== false) {
            $workspace['config'] = json_decode($zip->getFromName('config.json'), true);
        }
        if ($zip->locateName('system_prompt_template.json') !== false) {
            $workspace['system_prompt_template'] = json_decode($zip->getFromName('system_prompt_template.json'), true);
        }
        if ($zip->locateName('memories.json') !== false) {
            $workspace['memories'] = json_decode($zip->getFromName('memories.json'), true);
        }
        if ($zip->locateName('soul.md') !== false) {
            $workspace['soul_md'] = $zip->getFromName('soul.md');
        }

        $zip->close();

        return $workspace;
    }

    private function createNewAgent(array $workspace, string $teamId): array
    {
        $identity = $workspace['identity'] ?? [];

        $agent = Agent::create([
            'team_id' => $teamId,
            'name' => ($identity['name'] ?? 'Imported Agent').' (imported)',
            'slug' => null,
            'role' => $identity['role'] ?? null,
            'goal' => $identity['goal'] ?? null,
            'backstory' => $identity['backstory'] ?? null,
            'personality' => $identity['personality'] ?? null,
            'provider' => $identity['provider'] ?? 'anthropic',
            'model' => $identity['model'] ?? 'claude-sonnet-4-5',
            'status' => 'active',
            'system_prompt_template' => $workspace['system_prompt_template'] ?? null,
        ]);

        $toolsLinked = $this->linkTools($agent, $workspace['tools'] ?? [], $teamId);
        $memoriesImported = $this->importMemories($agent, $workspace['memories'] ?? [], $teamId);

        return [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'mode' => 'create',
            'tools_linked' => $toolsLinked,
            'memories_imported' => $memoriesImported,
        ];
    }

    private function mergeIntoAgent(array $workspace, string $agentId, string $teamId): array
    {
        $agent = Agent::where('team_id', $teamId)->findOrFail($agentId);
        $identity = $workspace['identity'] ?? [];

        $updates = array_filter([
            'role' => $identity['role'] ?? null,
            'goal' => $identity['goal'] ?? null,
            'backstory' => $identity['backstory'] ?? null,
            'personality' => $identity['personality'] ?? null,
            'system_prompt_template' => $workspace['system_prompt_template'] ?? null,
        ]);

        $agent->update($updates);

        $toolsLinked = $this->linkTools($agent, $workspace['tools'] ?? [], $teamId);
        $memoriesImported = $this->importMemories($agent, $workspace['memories'] ?? [], $teamId);

        return [
            'agent_id' => $agent->id,
            'agent_name' => $agent->name,
            'mode' => 'merge',
            'fields_updated' => array_keys($updates),
            'tools_linked' => $toolsLinked,
            'memories_imported' => $memoriesImported,
        ];
    }

    private function linkTools(Agent $agent, array $tools, string $teamId): int
    {
        $linked = 0;
        foreach ($tools as $toolData) {
            $tool = Tool::where('team_id', $teamId)
                ->where('name', $toolData['name'])
                ->first();

            if (! $tool) {
                continue;
            }

            $pivotData = [
                'priority' => $toolData['pivot']['priority'] ?? 0,
                'overrides' => json_encode($toolData['pivot']['overrides'] ?? []),
            ];

            if (isset($toolData['pivot']['approval_mode'])) {
                $pivotData['approval_mode'] = $toolData['pivot']['approval_mode'];
                $pivotData['approval_timeout_minutes'] = $toolData['pivot']['approval_timeout_minutes'] ?? 30;
                $pivotData['approval_timeout_action'] = $toolData['pivot']['approval_timeout_action'] ?? 'deny';
            }

            $agent->tools()->syncWithoutDetaching([$tool->id => $pivotData]);
            $linked++;
        }

        return $linked;
    }

    private function importMemories(Agent $agent, array $memories, string $teamId): int
    {
        $imported = 0;
        foreach ($memories as $mem) {
            Memory::create([
                'team_id' => $teamId,
                'agent_id' => $agent->id,
                'content' => $mem['content'] ?? '',
                'importance' => $mem['importance'] ?? 0.5,
                'confidence' => $mem['confidence'] ?? 0.8,
                'tags' => $mem['tags'] ?? ['imported'],
                'tier' => $mem['tier'] ?? 'working',
                'source_type' => 'import',
            ]);
            $imported++;
        }

        return $imported;
    }
}
