<?php

namespace App\Console\Commands;

use App\Mcp\Services\ConnectorMcpRegistrar;
use Illuminate\Console\Command;

class McpCacheConnectorToolsCommand extends Command
{
    protected $signature = 'mcp:cache-connector-tools {--clear : Remove all cached synthetic tool class files.}';

    protected $description = 'Generate (or clear) cached MCP tool class files for opt-in connectors. Activepieces-inspired auto-derivation.';

    public function handle(ConnectorMcpRegistrar $registrar): int
    {
        if ($this->option('clear')) {
            $registrar->clearCache();
            $this->info('Cleared synthetic MCP tool cache.');

            return self::SUCCESS;
        }

        $classes = $registrar->discoverToolClasses();
        $this->info('Generated '.count($classes).' synthetic MCP tool classes.');
        foreach ($classes as $cls) {
            $this->line("  ✓ {$cls}");
        }

        return self::SUCCESS;
    }
}
