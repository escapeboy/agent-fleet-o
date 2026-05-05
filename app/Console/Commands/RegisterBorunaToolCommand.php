<?php

namespace App\Console\Commands;

use App\Domain\Shared\Models\Team;
use App\Domain\Skill\Actions\RegisterBorunaToolAction;
use App\Domain\Skill\Services\BorunaPlatformService;
use Illuminate\Console\Command;

/**
 * Operator-side wrapper around RegisterBorunaToolAction.
 *
 * The Action contains the registration logic so the same contract is shared
 * with the user-side self-serve button in CreateSkillForm.
 */
class RegisterBorunaToolCommand extends Command
{
    protected $signature = 'boruna:register-tool
        {--team= : Team UUID. Defaults to the oldest team (single-team install).}
        {--binary='.RegisterBorunaToolAction::DEFAULT_BINARY.' : Absolute path to the boruna-mcp binary.}
        {--name=Boruna : Display name for the registered Tool.}
        {--force : Overwrite an existing Boruna tool for the team.}';

    protected $description = 'Register the bundled Boruna v0.2.0 binary as an mcp_stdio Tool for a team.';

    public function handle(RegisterBorunaToolAction $action, BorunaPlatformService $platform): int
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

        if (! $platform->isBinaryAllowed($binary)) {
            $this->warn("Boruna binary {$binary} is NOT in the mcp_stdio allowlist.");
            $this->line('  → Add it to MCP_STDIO_BINARY_ALLOWLIST in .env, or boruna_script Skills will fail at runtime.');
            $this->line('  → Example: MCP_STDIO_BINARY_ALLOWLIST='.$binary);
        }

        try {
            $result = $action->execute(
                teamId: $teamId,
                binary: $binary,
                name: (string) $this->option('name'),
                force: (bool) $this->option('force'),
            );
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tool = $result['tool'];

        if (! $result['created'] && ! $this->option('force')) {
            $this->info("Boruna tool already registered for team \"{$team->name}\": {$tool->id}");
            $this->line('  → Use --force to overwrite.');

            return self::SUCCESS;
        }

        $this->info($result['created']
            ? "Boruna tool registered for team \"{$team->name}\"."
            : "Boruna tool re-pointed for team \"{$team->name}\".");
        $this->line("  ID:       {$tool->id}");
        $this->line("  Subkind:  {$tool->subkind} (auto-tagged by Tool::booted hook)");
        $this->line("  Command:  {$binary}");

        return self::SUCCESS;
    }
}
