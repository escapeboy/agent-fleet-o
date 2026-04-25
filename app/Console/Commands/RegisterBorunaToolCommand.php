<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Register the Boruna binary bundled in the base image as an mcp_stdio Tool.
 *
 * Idempotent — re-running on a team that already has a Boruna tool returns
 * the existing tool ID with no changes (use --force to overwrite).
 *
 * Tool resolution by ExecuteBorunaScriptSkillAction relies on
 * `subkind = 'boruna'`, which the Tool model's saving hook auto-tags
 * when the transport_config command path contains "boruna".
 */
class RegisterBorunaToolCommand extends Command
{
    protected $signature = 'boruna:register-tool
        {--team= : Team UUID. Defaults to the oldest team (single-team install).}
        {--binary=/usr/local/bin/boruna-mcp : Absolute path to the boruna-mcp binary.}
        {--name=Boruna : Display name for the registered Tool.}
        {--force : Overwrite an existing Boruna tool for the team.}';

    protected $description = 'Register the bundled Boruna v0.2.0 binary as an mcp_stdio Tool for a team.';

    public function handle(): int
    {
        $teamId = (string) ($this->option('team') ?? Team::query()->withoutGlobalScopes()->orderBy('created_at')->value('id'));

        if ($teamId === '') {
            $this->error('No team found. Run app:install first, or pass --team=<uuid>.');

            return self::FAILURE;
        }

        /** @var Team|null $team */
        $team = Team::withoutGlobalScopes()->find($teamId);
        if (! $team) {
            $this->error("Team {$teamId} not found.");

            return self::FAILURE;
        }

        $binary = (string) $this->option('binary');
        if (! is_executable($binary)) {
            $this->error("Boruna binary not found or not executable at: {$binary}");
            $this->line('  → Did you rebuild the Docker image with the v0.2.0 BORUNA_VERSION arg?');

            return self::FAILURE;
        }

        $allowlist = (array) config('agent.mcp_stdio_binary_allowlist', []);
        $allowAny = (bool) config('agent.mcp_stdio_allow_any_binary', false);
        if (! $allowAny && ! in_array($binary, $allowlist, true)) {
            $this->warn("Boruna binary {$binary} is NOT in the mcp_stdio allowlist.");
            $this->line('  → Add it to MCP_STDIO_BINARY_ALLOWLIST in .env, or boruna_script Skills will fail at runtime.');
            $this->line('  → Example: MCP_STDIO_BINARY_ALLOWLIST='.$binary);
        }

        $existing = Tool::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('subkind', 'boruna')
            ->first();

        if ($existing && ! $this->option('force')) {
            $this->info("Boruna tool already registered for team \"{$team->name}\": {$existing->id}");
            $this->line('  → Use --force to overwrite.');

            return self::SUCCESS;
        }

        if ($existing && $this->option('force')) {
            $existing->update([
                'transport_config' => ['command' => $binary, 'args' => []],
                'status' => ToolStatus::Active,
            ]);

            $this->info("Boruna tool re-pointed at {$binary} for team \"{$team->name}\": {$existing->id}");

            return self::SUCCESS;
        }

        $name = (string) $this->option('name');
        $tool = Tool::create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => 'Boruna deterministic .ax script runtime — capability-safe VM. Bundled with the agent-fleet base image (v0.2.0).',
            'type' => ToolType::McpStdio,
            'status' => ToolStatus::Active,
            'transport_config' => ['command' => $binary, 'args' => []],
            'tool_definitions' => [],
            'settings' => [],
        ]);

        $this->info("Boruna tool registered for team \"{$team->name}\".");
        $this->line("  ID:       {$tool->id}");
        $this->line("  Subkind:  {$tool->subkind} (auto-tagged by Tool::booted hook)");
        $this->line("  Command:  {$binary}");

        return self::SUCCESS;
    }
}
