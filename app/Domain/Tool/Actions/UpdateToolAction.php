<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Str;

class UpdateToolAction
{
    public function execute(
        Tool $tool,
        ?string $name = null,
        ?string $description = null,
        ?array $transportConfig = null,
        ?array $credentials = null,
        ?array $toolDefinitions = null,
        ?array $settings = null,
        ?ToolStatus $status = null,
    ): Tool {
        $data = array_filter([
            'name' => $name,
            'slug' => $name ? Str::slug($name) : null,
            'description' => $description,
            'transport_config' => $transportConfig,
            'tool_definitions' => $toolDefinitions,
            'settings' => $settings,
            'status' => $status,
        ], fn ($v) => $v !== null);

        // Credentials handled separately to avoid overwriting with null
        if ($credentials !== null) {
            $data['credentials'] = $credentials;
        }

        $tool->update($data);

        return $tool->fresh();
    }
}
