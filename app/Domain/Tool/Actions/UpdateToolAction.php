<?php

namespace App\Domain\Tool\Actions;

use App\Domain\Tool\Enums\ToolRiskLevel;
use App\Domain\Tool\Enums\ToolStatus;
use App\Domain\Tool\Models\Tool;
use Illuminate\Support\Str;

class UpdateToolAction
{
    public function execute(
        Tool $tool,
        // Note: platform tools are read-only for teams; super-admin can update via withoutGlobalScopes
        ?string $name = null,
        ?string $description = null,
        ?array $transportConfig = null,
        ?array $credentials = null,
        ?array $toolDefinitions = null,
        ?array $settings = null,
        ?ToolStatus $status = null,
        ?ToolRiskLevel $riskLevel = null,
        ?string $credentialId = null,
        bool $clearCredentialId = false,
    ): Tool {
        if ($tool->isPlatformTool()) {
            throw new \RuntimeException('Platform tools cannot be modified by teams.');
        }

        $data = array_filter([
            'name' => $name,
            'slug' => $name ? Str::slug($name) : null,
            'description' => $description,
            'transport_config' => $transportConfig,
            'tool_definitions' => $toolDefinitions,
            'settings' => $settings,
            'status' => $status,
            'risk_level' => $riskLevel,
        ], fn ($v) => $v !== null);

        // Credentials handled separately to avoid overwriting with null
        if ($credentials !== null) {
            $data['credentials'] = $credentials;
        }

        // credential_id can be explicitly set or cleared
        if ($credentialId !== null) {
            $data['credential_id'] = $credentialId;
        } elseif ($clearCredentialId) {
            $data['credential_id'] = null;
        }

        $tool->update($data);

        return $tool->fresh();
    }
}
