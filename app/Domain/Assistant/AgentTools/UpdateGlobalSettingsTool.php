<?php

namespace App\Domain\Assistant\AgentTools;

use App\Models\GlobalSetting;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class UpdateGlobalSettingsTool implements Tool
{
    private const ALLOWED_KEYS = [
        'assistant_llm_provider', 'assistant_llm_model',
        'default_llm_provider', 'default_llm_model',
        'budget_cap_credits', 'rate_limit_rpm',
        'outbound_rate_limit', 'experiment_timeout_seconds',
        'weekly_digest_enabled', 'audit_retention_days',
    ];

    public function name(): string
    {
        return 'update_global_settings';
    }

    public function description(): string
    {
        return 'Update global platform settings. Allowed keys: '.implode(', ', self::ALLOWED_KEYS).'. Returns previous and new values.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'settings_json' => $schema->string()->required()->description('JSON object of setting key-value pairs to update. Example: {"default_llm_provider":"anthropic","budget_cap_credits":50000}'),
        ];
    }

    public function handle(Request $request): string
    {
        $settings = json_decode($request->get('settings_json'), true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($settings)) {
            return json_encode(['error' => 'Invalid JSON: '.json_last_error_msg()]);
        }

        $unknownKeys = array_diff(array_keys($settings), self::ALLOWED_KEYS);
        if (! empty($unknownKeys)) {
            return json_encode(['error' => 'Unknown keys: '.implode(', ', $unknownKeys).'. Allowed: '.implode(', ', self::ALLOWED_KEYS)]);
        }

        $updated = [];
        foreach ($settings as $key => $value) {
            $previous = GlobalSetting::get($key);
            GlobalSetting::set($key, $value);
            $updated[$key] = ['previous' => $previous, 'new' => $value];
        }

        return json_encode(['success' => true, 'updated_count' => count($updated), 'changes' => $updated]);
    }
}
