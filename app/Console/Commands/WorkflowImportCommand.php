<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Domain\Workflow\Actions\ImportWorkflowAction;
use Illuminate\Console\Command;

class WorkflowImportCommand extends Command
{
    protected $signature = 'workflow:import {file} {--team=}';

    protected $description = 'Import a workflow from a JSON or YAML file';

    public function handle(ImportWorkflowAction $action): int
    {
        $filePath = $this->argument('file');

        if (! file_exists($filePath)) {
            $this->error("File not found: {$filePath}");

            return self::FAILURE;
        }

        $fileSize = filesize($filePath);
        if ($fileSize > 1_048_576) {
            $this->error('File exceeds the maximum size of 1 MB.');

            return self::FAILURE;
        }

        $content = file_get_contents($filePath);

        $teamId = $this->option('team');
        if (! $teamId) {
            $team = Team::withoutGlobalScopes()->first();
            if (! $team) {
                $this->error('No team found. Use --team to specify a team ID.');

                return self::FAILURE;
            }
            $teamId = $team->id;
        }

        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            $this->error("Team not found: {$teamId}");

            return self::FAILURE;
        }

        // Use the team owner as the user
        $userId = $team->owner_id ?? $team->users()->first()?->id;
        if (! $userId) {
            $this->error('No user found for the team.');

            return self::FAILURE;
        }

        try {
            $result = $action->execute($content, $teamId, $userId);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $workflow = $result['workflow'];
        $this->info("Workflow imported: {$workflow->name} (ID: {$workflow->id})");

        if ($result['checksum_valid'] === false) {
            $this->warn('Checksum verification failed — data may have been modified.');
        }

        if (! empty($result['unresolved_references'])) {
            $this->warn('Unresolved references:');
            foreach ($result['unresolved_references'] as $ref) {
                $this->warn("  - {$ref['type']}: {$ref['name']}");
            }
        }

        return self::SUCCESS;
    }
}
