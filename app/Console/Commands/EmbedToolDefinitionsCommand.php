<?php

namespace App\Console\Commands;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use App\Domain\Tool\Services\SemanticToolSelector;
use App\Domain\Tool\Services\ToolTranslator;
use Illuminate\Console\Command;

class EmbedToolDefinitionsCommand extends Command
{
    protected $signature = 'tools:embed
        {--team= : Only process tools for a specific team ID}
        {--tool= : Only process a specific tool ID}';

    protected $description = 'Generate vector embeddings for tool definitions (used for semantic tool selection)';

    public function handle(SemanticToolSelector $selector, ToolTranslator $translator): int
    {
        $query = Tool::withoutGlobalScopes()
            ->where('status', ToolStatus::Active);

        if ($teamId = $this->option('team')) {
            $query->where('team_id', $teamId);
        }

        if ($toolId = $this->option('tool')) {
            $query->where('id', $toolId);
        }

        $total = 0;
        $toolCount = 0;

        $query->chunk(50, function ($tools) use ($selector, $translator, &$total, &$toolCount) {
            foreach ($tools as $tool) {
                $prismTools = $translator->toPrismTools($tool);

                $defs = [];
                foreach ($prismTools as $prismTool) {
                    $defs[] = [
                        'name' => $prismTool->name(),
                        'description' => $prismTool->description(),
                    ];
                }

                if (empty($defs)) {
                    continue;
                }

                $count = $selector->embedToolDefinitions($tool->id, $tool->team_id, $defs);
                $total += $count;
                $toolCount++;

                $this->line("  Embedded {$count} definitions for tool: {$tool->name}");
            }
        });

        $this->info("Done. Embedded {$total} tool definitions across {$toolCount} tools.");

        return self::SUCCESS;
    }
}
