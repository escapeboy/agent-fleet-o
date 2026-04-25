<?php

namespace App\Domain\Skill\Actions;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Str;

/**
 * Registers (or re-points) the bundled Boruna mcp_stdio binary as a Tool for a team.
 *
 * Used by both the `boruna:register-tool` artisan command (operator path) and the
 * Livewire CreateSkillForm self-serve enable button (user path), so the
 * idempotent contract lives in exactly one place.
 *
 * Tool resolution by ExecuteBorunaScriptSkillAction relies on
 * `subkind = 'boruna'`, which the Tool model's saving hook auto-tags
 * when the transport_config command path contains "boruna".
 */
class RegisterBorunaToolAction
{
    public const DEFAULT_BINARY = '/usr/local/bin/boruna-mcp';

    /**
     * @return array{tool: Tool, created: bool, message: string}
     *
     * @throws \RuntimeException when the binary is missing/not executable.
     */
    public function execute(
        string $teamId,
        string $binary = self::DEFAULT_BINARY,
        string $name = 'Boruna',
        bool $force = false,
    ): array {
        if (! is_executable($binary)) {
            throw new \RuntimeException(
                "Boruna binary not found or not executable at: {$binary}. "
                ."Rebuild the Docker image with the v0.2.0 BORUNA_VERSION arg.",
            );
        }

        $existing = Tool::withoutGlobalScopes()
            ->where('team_id', $teamId)
            ->where('subkind', 'boruna')
            ->first();

        if ($existing && ! $force) {
            return [
                'tool' => $existing,
                'created' => false,
                'message' => "Boruna already registered (Tool {$existing->id}).",
            ];
        }

        if ($existing && $force) {
            $existing->update([
                'transport_config' => ['command' => $binary, 'args' => []],
                'status' => ToolStatus::Active,
            ]);

            return [
                'tool' => $existing->fresh(),
                'created' => false,
                'message' => "Boruna re-pointed at {$binary} (Tool {$existing->id}).",
            ];
        }

        $tool = Tool::create([
            'team_id' => $teamId,
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
            'description' => 'Boruna deterministic .ax script runtime — capability-safe VM. Bundled with the agent-fleet base image (v0.2.0).',
            'type' => ToolType::McpStdio,
            // Set subkind explicitly here — this code path knows it's
            // registering Boruna, regardless of whether the binary path
            // happens to contain the substring "boruna" (the saving-hook's
            // weaker fallback signal).
            'subkind' => 'boruna',
            'status' => ToolStatus::Active,
            'transport_config' => ['command' => $binary, 'args' => []],
            'tool_definitions' => [],
            'settings' => [],
        ]);

        return [
            'tool' => $tool,
            'created' => true,
            'message' => "Boruna registered for team (Tool {$tool->id}).",
        ];
    }
}
