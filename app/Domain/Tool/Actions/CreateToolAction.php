<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Enums\ToolType;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Str;

class CreateToolAction
{
    public function execute(
        ?string $teamId,
        string $name,
        ToolType $type,
        string $description = '',
        array $transportConfig = [],
        array $credentials = [],
        array $toolDefinitions = [],
        array $settings = [],
        bool $isPlatform = false,
        ?string $credentialId = null,
    ): Tool {
        return Tool::withoutGlobalScopes()->create([
            'team_id' => $teamId,
            'credential_id' => $credentialId,
            'is_platform' => $isPlatform || $teamId === null,
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => $description,
            'type' => $type,
            'status' => ToolStatus::Active,
            'transport_config' => $transportConfig,
            'credentials' => $credentials,
            'tool_definitions' => $toolDefinitions,
            'settings' => $settings,
        ]);
    }
}
