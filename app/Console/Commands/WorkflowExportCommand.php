<?php

namespace App\Console\Commands;

use App\Domain\Workflow\Actions\ExportWorkflowAction;
use App\Domain\Workflow\Models\Workflow;
use Illuminate\Console\Command;
use Symfony\Component\Yaml\Yaml;

class WorkflowExportCommand extends Command
{
    protected $signature = 'workflow:export {id} {--format=json} {--output=}';

    protected $description = 'Export a workflow to JSON or YAML format';

    public function handle(ExportWorkflowAction $action): int
    {
        $workflow = Workflow::withoutGlobalScopes()->find($this->argument('id'));

        if (! $workflow) {
            $this->error('Workflow not found.');

            return self::FAILURE;
        }

        $format = $this->option('format');
        if (! in_array($format, ['json', 'yaml'])) {
            $this->error('Format must be json or yaml.');

            return self::FAILURE;
        }

        $result = $action->execute($workflow, $format);

        if ($format === 'json') {
            $output = json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } else {
            $output = $result; // Already a YAML string
        }

        $outputPath = $this->option('output');
        if ($outputPath) {
            file_put_contents($outputPath, $output);
            $this->info("Workflow exported to {$outputPath}");
        } else {
            $this->line($output);
        }

        return self::SUCCESS;
    }
}
