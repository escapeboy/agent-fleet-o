<?php

namespace App\Mcp\Tools\System;

use App\Domain\Shared\Services\DeploymentMode;
use App\Models\GlobalSetting;
use App\Mcp\Attributes\AssistantTool;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[IsIdempotent]
#[IsDestructive]
#[AssistantTool('destructive')]
class GlobalSettingsUpdateTool extends Tool
{
    protected string $name = 'global_settings_update';

    protected string $description = 'Update global platform settings. Only whitelisted keys are allowed. Returns previous and new values for each updated key.';

    /** Keys that are safe to update via MCP */
    private const ALLOWED_KEYS = [
        'assistant_llm_provider',
        'assistant_llm_model',
        'default_llm_provider',
        'default_llm_model',
        'budget_cap_credits',
        'rate_limit_rpm',
        'outbound_rate_limit',
        'experiment_timeout_seconds',
        'weekly_digest_enabled',
        'audit_retention_days',
    ];

    public function schema(JsonSchema $schema): array
    {
        return [
            'settings' => $schema->object()
                ->description('Key-value pairs to update. Allowed keys: '.implode(', ', self::ALLOWED_KEYS))
                ->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        // In cloud mode only super-admins may update global settings.
        // In self-hosted mode any authenticated user may do so (single-team, owner controls the install).
        if (app(DeploymentMode::class)->isCloud() && ! auth()->user()?->is_super_admin) {
            return Response::error('Access denied: super admin privileges required.');
        }

        $settings = $request->get('settings');

        if (! is_array($settings) || empty($settings)) {
            return Response::error('settings must be a non-empty object of key-value pairs.');
        }

        $unknownKeys = array_diff(array_keys($settings), self::ALLOWED_KEYS);
        if (! empty($unknownKeys)) {
            return Response::error(
                'Unknown or disallowed setting key(s): '.implode(', ', $unknownKeys).
                '. Allowed keys: '.implode(', ', self::ALLOWED_KEYS),
            );
        }

        $updated = [];
        foreach ($settings as $key => $value) {
            $previous = GlobalSetting::get($key);
            GlobalSetting::set($key, $value);
            $updated[$key] = ['previous' => $previous, 'new' => $value];
        }

        return Response::text(json_encode([
            'success' => true,
            'updated_count' => count($updated),
            'changes' => $updated,
        ]));
    }
}
