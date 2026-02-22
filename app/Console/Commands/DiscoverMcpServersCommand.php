<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Actions\ImportMcpServersAction;
use App\Domain\Tool\Services\McpConfigDiscovery;
use Illuminate\Console\Command;

class DiscoverMcpServersCommand extends Command
{
    protected $signature = 'tools:discover
        {--source= : Scan a specific IDE only (claude_desktop, claude_code, cursor, windsurf, kiro, vscode)}
        {--import : Import discovered servers into the database}
        {--dry-run : Show what would be imported without making changes}
        {--include-disabled : Include servers marked as disabled in source config}';

    protected $description = 'Discover MCP servers configured on the host machine';

    public function handle(McpConfigDiscovery $discovery, ImportMcpServersAction $importer): int
    {
        $this->components->info('Scanning for MCP server configurations...');
        $this->newLine();

        // Scan sources
        $source = $this->option('source');

        if ($source) {
            $labels = $discovery->allSourceLabels();
            if (! isset($labels[$source])) {
                $this->components->error("Unknown source: {$source}. Valid sources: ".implode(', ', array_keys($labels)));

                return self::FAILURE;
            }

            $result = $discovery->scanSource($source);
            $servers = $result['servers'];
            $sources = [];
            if (! empty($servers)) {
                $sources[$source] = [
                    'label' => $labels[$source],
                    'file' => $result['file'] ?? '',
                    'count' => count($servers),
                ];
            }
        } else {
            $scanResult = $discovery->scanAllSources();
            $servers = $scanResult['servers'];
            $sources = $scanResult['sources'];
        }

        if (empty($servers)) {
            $this->components->warn('No MCP servers found.');

            if ($discovery->isBridgeMode()) {
                $this->components->info('Running in Docker bridge mode. Ensure the bridge server exposes /mcp-configs.');
            } else {
                $this->components->info('Configure MCP servers in your IDE (Claude Desktop, Cursor, VS Code, etc.) first.');
            }

            return self::SUCCESS;
        }

        // Display source summary
        foreach ($sources as $sourceKey => $info) {
            $this->components->twoColumnDetail(
                $info['label'],
                "<fg=cyan>{$info['count']} server(s)</>"
            );
        }

        $this->newLine();

        // Display server table
        $rows = [];
        foreach ($servers as $server) {
            $status = $server['disabled'] ? '<fg=yellow>Disabled</>' : '<fg=green>Active</>';

            $warnings = '';
            if (! empty($server['warnings'])) {
                $warnings = ' <fg=yellow>(!)</>';
            }

            $rows[] = [
                $server['name'],
                $server['source'],
                $server['type'] === 'mcp_stdio' ? 'stdio' : 'HTTP',
                $status.$warnings,
            ];
        }

        $this->table(['Name', 'Source', 'Type', 'Status'], $rows);

        $totalCount = count($servers);
        $disabledCount = count(array_filter($servers, fn ($s) => $s['disabled']));
        $activeCount = $totalCount - $disabledCount;

        $this->newLine();
        $this->components->info("Found {$totalCount} server(s): {$activeCount} active, {$disabledCount} disabled.");

        // Show warnings if any
        foreach ($servers as $server) {
            if (! empty($server['warnings'])) {
                foreach ($server['warnings'] as $warning) {
                    $this->components->warn("{$server['name']}: {$warning}");
                }
            }
        }

        // Import mode
        if ($this->option('import') || $this->option('dry-run')) {
            return $this->handleImport($servers, $importer);
        }

        $this->newLine();
        $this->components->info('Run with --import to import, or --dry-run to preview.');

        return self::SUCCESS;
    }

    private function handleImport(array $servers, ImportMcpServersAction $importer): int
    {
        $isDryRun = $this->option('dry-run');
        $includeDisabled = $this->option('include-disabled');

        $team = Team::first();

        if (! $team) {
            $this->components->error('No team found. Run php artisan app:install first.');

            return self::FAILURE;
        }

        if ($isDryRun) {
            $this->newLine();
            $this->components->info('Dry run — no changes will be made.');

            $importable = array_filter($servers, function ($s) use ($includeDisabled) {
                if (! $includeDisabled && $s['disabled']) {
                    return false;
                }

                return true;
            });

            $this->components->info(count($importable).' server(s) would be imported.');

            return self::SUCCESS;
        }

        if (! $this->option('no-interaction') && ! $this->components->confirm('Import these servers?', true)) {
            $this->components->info('Cancelled.');

            return self::SUCCESS;
        }

        $result = $importer->execute(
            teamId: $team->id,
            servers: $servers,
            skipExisting: true,
            importDisabled: $includeDisabled,
        );

        $this->newLine();
        $this->components->twoColumnDetail('Imported', "<fg=green>{$result->imported}</>");
        $this->components->twoColumnDetail('Skipped', "<fg=yellow>{$result->skipped}</>");

        if ($result->failed > 0) {
            $this->components->twoColumnDetail('Failed', "<fg=red>{$result->failed}</>");
        }

        if ($result->hasCredentialPlaceholders()) {
            $this->newLine();
            $this->components->warn(
                "{$result->credentialCount()} server(s) have imported credentials. Review them in Settings > Tools."
            );
        }

        // Show per-server details
        foreach ($result->details as $detail) {
            if ($detail['status'] === 'failed') {
                $this->components->error("  {$detail['name']}: {$detail['reason']}");
            }
        }

        return self::SUCCESS;
    }
}
